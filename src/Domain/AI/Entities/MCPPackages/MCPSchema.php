<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionDocComment;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\OverwritePropertyName;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Exceptions\TypeDefinitionMissingOrWrong;
use Doctrine\ORM\Mapping\Entity;
use JsonException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/** @phpstan-consistent-constructor */
class MCPSchema extends ValueObject
{
    public const string FUNCTION_TYPE_INPUT = 'INPUT';

    public const string FUNCTION_TYPE_OUTPUT = 'OUTPUT';

    public ?string $type = 'object';

    /** @var string|null The function's description */
    public ?string $description;

    /**
     * Examples for this tool (extracted from PHPDoc @example tags).
     *
     * If an @example block contains valid JSON, it is stored decoded (associative array),
     * otherwise it is stored as raw string.
     *
     * @var array<int, array|string>
     */
    public ?array $examples;

    /** @var MCPObjectProperty[] Properties of in or output object */
    public array $properties = [];

    #[OverwritePropertyName('$defs')]
    public ?array $defs = null;

    #[OverwritePropertyName('$schema')]
    public string $schema = 'https://json-schema.org/draft/2020-12/schema';

    /**
     * True if any of this schema's parameters is a QueryOptions type (filters/orderBy/expand/select). Set during
     * doc-gen by {@see MCPObjectProperty}; consumed by the agent loop to emit the shared QueryOptions grammar once in
     * the system context. {@see HideProperty} — internal build signal, never part of the wire JSON-Schema.
     */
    #[HideProperty]
    public bool $usesQueryOptions = false;

    /**
     * True if any parameter is a #[SharedRequestParameter] (outputFormat/noCache/skip/top/skiptoken). Set during
     * doc-gen by {@see MCPObjectProperty}; consumed by the agent loop to emit the shared-request-parameters doc once in
     * the system context. {@see HideProperty} — internal build signal, never part of the wire JSON-Schema.
     */
    #[HideProperty]
    public bool $usesSharedParams = false;

    /**
     * True while this schema documents what the model SENDS (the tool's parameters) rather than what it receives
     * back. Only then are {@see MCPOutputOnlyProperty} properties omitted — see {@see omitsOutputOnlyProperty()}.
     * {@see HideProperty} — internal build signal, never part of the wire JSON-Schema.
     */
    #[HideProperty]
    public bool $isInputSchema = false;

    /**
     * Whether $reflectionProperty is dropped from THIS schema because it is server-filled output
     * ({@see MCPOutputOnlyProperty}) and this is the INPUT schema. Asked at every level that reflects properties
     * into a schema — the tool's own parameters ({@see createFromReflectionMethod()}), a nested object's inline
     * properties and a `$defs` definition ({@see MCPObjectProperty}, {@see MCPObjectDefinition}) — so a value
     * object costs its resolved half only where it is actually returned.
     */
    public function omitsOutputOnlyProperty(\ReflectionProperty $reflectionProperty): bool
    {
        return $this->isInputSchema && $reflectionProperty->getAttributes(MCPOutputOnlyProperty::class) !== [];
    }

    public static function createFromReflectionMethod(
        MCPPackageDocumentation|MCPBundleDocumentation $bundleOrPackageDocumentation,
        MCPTool $function,
        string $functionType,
        \ReflectionClass $mcpPackageReflectionClass,
        ReflectionMethod $reflectionMethod,
        bool $addObjectsToDef = false,
        int $maxDepth = 6,
    ): static
    {
        $mcpSchema = new static();
        $mcpSchema->isInputSchema = $functionType === self::FUNCTION_TYPE_INPUT;
        // Process input parameters
        if ($functionType === self::FUNCTION_TYPE_INPUT) {
            // For input, include examples
            $docCommentText = $reflectionMethod->getDocComment();
            if ($docCommentText) {
                $docComment = new ReflectionDocComment($docCommentText, $mcpPackageReflectionClass, $reflectionMethod);
                $rawExamples = $docComment->getExamples();

                if (count($rawExamples) && !isset($mcpSchema->examples)) {
                    $mcpSchema->examples = [];
                }
                foreach ($rawExamples as $rawExample) {
                    $candidate = trim($rawExample);
                    if ($candidate === '') {
                        continue;
                    }
                    try {
                        $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
                        // We only want to turn JSON objects/arrays into actual example objects.
                        // Scalars should remain strings to keep schema output stable.
                        if (is_array($decoded)) {
                            $mcpSchema->examples[] = $decoded;
                        } else {
                            $mcpSchema->examples[] = $rawExample;
                        }
                    } catch (JsonException) {
                        $mcpSchema->examples[] = $rawExample;
                    }
                }
            }

            // MCP-only, per-endpoint query-options narrowing declared on THIS tool method (never on the shared DTO).
            // The listed families are dropped from the agent-facing schema while staying present/hydrated server-side.
            $hiddenQueryOptionFamilies = [];
            $hiddenQueryOptionsAttributes = $reflectionMethod->getAttributes(MCPHiddenQueryOptions::class);
            if ($hiddenQueryOptionsAttributes !== []) {
                $hiddenQueryOptionFamilies = $hiddenQueryOptionsAttributes[0]->newInstance()->hidden;
            }

            // MCP-only, per-endpoint allow-list of the filter / sort properties this tool offers the agent — the
            // `filters` parameter documents ONLY these instead of every column the base entity carries.
            $exposedFilterProperties = null;
            $exposedFilterPropertiesAttributes = $reflectionMethod->getAttributes(MCPExposedFilterProperties::class);
            if ($exposedFilterPropertiesAttributes !== []) {
                /** @var MCPExposedFilterProperties $exposedFilterProperties */
                $exposedFilterProperties = $exposedFilterPropertiesAttributes[0]->newInstance();
            }

            $parameters = $reflectionMethod->getParameters();
            foreach ($parameters as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType) {
                    if ($type->isBuiltin()) {
                        throw new TypeDefinitionMissingOrWrong(
                            "MCP Tool does not support basic types as input parameters, Pacakge $bundleOrPackageDocumentation->name in function $function->name parameter $parameter->name"
                        );
                    }
                    // We ignore services, as these will be injected
                    if (is_a($type->getName(), Service::class, true)) {
                        continue;
                    }
                    // Server-provided inputs are filled by the executor from the agent-loop call context, never by the
                    // model — exclude from the schema (must come BEFORE the ValueObject check: the carrier IS a
                    // ValueObject, but it must not surface as a model-facing input). See MCPServerProvidedInput.
                    if (is_a($type->getName(), MCPServerProvidedInput::class, true)) {
                        continue;
                    }
                    if (!(is_a($type->getName(), RequestDto::class, true) || is_a(
                            $type->getName(),
                            ValueObject::class,
                            true
                        ) || is_a($type->getName(), Entity::class, true))) {
                        throw new TypeDefinitionMissingOrWrong(
                            "MCP Tool has to use either inputs of Type RequestDTO, ValueObject or Entity, other types are not supported in Pacakge $bundleOrPackageDocumentation->name in function $function->name parameter $parameter->name"
                        );
                    }
                    $typeReflectionClass = ReflectionClass::instance($type->getName());
                    foreach (
                        $typeReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty
                    ) {
                        if ($reflectionProperty->hasAttribute(HideProperty::class) || $reflectionProperty->isStatic()
                            || $mcpSchema->omitsOutputOnlyProperty($reflectionProperty)) {
                            continue;
                        }
                        $objectProperty = MCPObjectProperty::createFromReflectionProperty(
                            $mcpSchema,
                            $typeReflectionClass,
                            $reflectionProperty,
                            $addObjectsToDef,
                            $maxDepth,
                            [],
                            $hiddenQueryOptionFamilies
                        );
                        // null = the property is intentionally omitted from the schema (e.g. a #[DtoQueryOptions(expose:)]
                        // narrowing, or maxDepth) — skip it; do not store a null entry.
                        if ($objectProperty !== null) {
                            $mcpSchema->properties[$reflectionProperty->getName()] = $objectProperty;
                        }
                    }
                    if ($exposedFilterProperties !== null && isset($mcpSchema->properties['filters'])) {
                        $mcpSchema->properties['filters']->narrowAllowedFilterPropertiesTo($exposedFilterProperties, $type->getName());
                    }
                } elseif ($type instanceof ReflectionUnionType) {
                    throw new TypeDefinitionMissingOrWrong(
                        "MCP Tool does not support Union Types as input parameters, Pacakge $bundleOrPackageDocumentation->name in function $function->name parameter $parameter->name"
                    );
                }
            }
        } elseif ($functionType === self::FUNCTION_TYPE_OUTPUT) {
            // Process output type
            $outputType = $reflectionMethod->getReturnType();
            if ($outputType instanceof ReflectionNamedType) {
                if ($outputType->isBuiltin()) {
                    throw new TypeDefinitionMissingOrWrong(
                        "MCP Tool does not support basic types as output, Pacakge $bundleOrPackageDocumentation->name in function $function->name"
                    );
                }
                if (!(is_a($outputType->getName(), RestResponseDto::class, true) || is_a(
                        $outputType->getName(),
                        ValueObject::class,
                        true
                    ) || is_a($outputType->getName(), Entity::class, true))) {
                    throw new TypeDefinitionMissingOrWrong(
                        "MCP Tool has to use either outputs of Type ResponseDto, ValueObject or Entity, other types are not supported in Pacakge $bundleOrPackageDocumentation->name in function $function->name"
                    );
                }
                $outputTypeReflectionClass = ReflectionClass::instance($outputType->getName());
                foreach (
                    $outputTypeReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty
                ) {
                    if ($reflectionProperty->hasAttribute(HideProperty::class) || $reflectionProperty->isStatic()) {
                        continue;
                    }
                    $objectProperty = MCPObjectProperty::createFromReflectionProperty(
                        $mcpSchema,
                        $outputTypeReflectionClass,
                        $reflectionProperty,
                        $addObjectsToDef,
                        $maxDepth
                    );
                    $mcpSchema->properties[$reflectionProperty->getName()] = $objectProperty;
                }
            } elseif ($outputType instanceof ReflectionUnionType) {
                throw new TypeDefinitionMissingOrWrong(
                    "MCP Tool does not support Union Types as output, Pacakge $bundleOrPackageDocumentation->name in function "
                );
            }
        }
        return $mcpSchema;
    }

    public function hasObjectDefinitionForIndex(string $index): bool
    {
        if (!isset($this->defs) || !is_array($this->defs)) {
            return false;
        }
        $segments = array_values(array_filter(explode('/', trim($index, '/')), static fn($s) => $s !== ''));
        if (empty($segments)) {
            return false;
        }
        $node = $this->defs;
        foreach ($segments as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return false;
            }
            $node = $node[$seg];
        }
        return isset($node);
    }

    public function addObjectDefinitionForIndex(string $index, MCPObjectDefinition $objectDefinition): void
    {
        if (!isset($this->defs) || !is_array($this->defs)) {
            $this->defs = [];
        }
        $segments = array_values(array_filter(explode('/', trim($index, '/')), static fn($s) => $s !== ''));
        if (empty($segments)) {
            return;
        }
        $node =& $this->defs;
        $lastIdx = count($segments) - 1;
        foreach ($segments as $i => $seg) {
            $isLast = ($i === $lastIdx);
            if ($isLast) {
                if (!array_key_exists($seg, $node)) {
                    $node[$seg] = $objectDefinition;
                }
            } else {
                if (!isset($node[$seg]) || !is_array($node[$seg])) {
                    $node[$seg] = [];
                }
                $node =& $node[$seg];
            }
        }
    }
}