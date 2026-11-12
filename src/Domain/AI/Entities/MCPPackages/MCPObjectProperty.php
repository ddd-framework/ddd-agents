<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Infrastructure\Validation\Constraints\Choice as DDDChoice;
use Symfony\Component\Validator\Constraints\Choice;
use DDD\Domain\Base\Entities\QueryOptions\ExpandDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinition;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionDocComment;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\OverwritePropertyName;
use DDD\Infrastructure\Traits\Serializer\Attributes\SerializeInToonFormat;
use DDD\Presentation\Base\OpenApi\Attributes\SharedRequestParameter;
use DDD\Presentation\Base\OpenApi\Exceptions\TypeDefinitionMissingOrWrong;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait;
use ReflectionAttribute;
use ReflectionException;
use ReflectionNamedType;
use ReflectionUnionType;

/** @phpstan-consistent-constructor */
class MCPObjectProperty extends ValueObject
{
    public const array BUILTIN_TYPES_TO_NAMES_ALLOCATION = [
        'int' => 'integer',
        'string' => 'string',
        'bool' => 'boolean',
        'float' => 'number',
        'array' => 'array',
        'object' => 'object',
        'null' => 'null'
    ];

    /**
     * Shared request parameters ({@see SharedRequestParameter}) that are HIDDEN from the agent-facing MCP tool schema.
     * These are controlled by the MCP executor server-side, so exposing them only lets a model pick a worse path — e.g.
     * `outputFormat=structured` bypasses the tools' `toLlmMarkdown` rendering and returns raw structured data (a nested
     * list of the result was dropped, so the agent wrongly reported it as empty). Keyed by NAME (not an attribute):
     * the params live on the framework base `RequestDto` (mgamadeus/ddd), which is MCP-agnostic — MCP is a concept of
     * the agent layer, so the hide list stays here in the MCP layer. REST/OpenApi + the executor keep the param fully functional.
     *
     * @var string[]
     */
    public const array MCP_HIDDEN_SHARED_PARAMETERS = ['outputFormat'];

    /** @var MCPObjectProperty[] Per-name cache of the (endpoint-invariant) shared-parameter rendering. */
    protected static array $sharedParameterCache = [];
    #[HideProperty]
    public string $name;

    public string|array $type;

    public ?string $description;

    public int|string|float|bool|null $default;

    public array $examples;

    /** @var array<int, string|int|float> The values a `#[Choice]` property accepts — the model reads them instead of guessing. */
    public array $enum;

    /** @var string[] */
    public array $required;

    public MCPObjectProperty $items;

    public array $oneOf;

    #[OverwritePropertyName('$ref')]
    public ?string $ref = null;

    #[HideProperty]
    public ?string $objectType;

    /** @var MCPObjectProperty[] Properties of object, used if objects references are not added as $def */
    public array $properties;

    /**
     * The values a scalar property accepts by its `#[Choice]` constraint, for the schema's `enum`. The empty string
     * some constraints list as "not set" is left out: the executor reads an empty string as "not passed", so it is
     * no value to offer. A constraint that resolves its choices through a callback is resolved here (ddd 2.70.1 resolves
     * callbacks lazily, so the attribute's own `choices` stays null).
     *
     * @return array<int, string|int|float>
     */
    protected static function resolveChoiceValues(ReflectionProperty $reflectionProperty): array
    {
        $reflectionType = $reflectionProperty->getType();
        if (!$reflectionType instanceof ReflectionNamedType || !in_array($reflectionType->getName(), ['string', 'int', 'float'], true)) {
            return [];
        }
        foreach ($reflectionProperty->getAttributes(Choice::class, \ReflectionAttribute::IS_INSTANCEOF) as $choiceAttribute) {
            try {
                $choices = DDDChoice::getChoicesResolvedFromConstraint($choiceAttribute->newInstance());
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($choices)) {
                continue;
            }
            $choiceValues = array_values(array_filter(
                $choices,
                static fn (mixed $choice): bool => (is_string($choice) && $choice !== '') || is_int($choice) || is_float($choice)
            ));
            if ($choiceValues !== []) {
                return $choiceValues;
            }
        }
        return [];
    }

    /**
     * Rewrites THIS `filters` parameter's description to the properties the owning tool exposes
     * ({@see MCPExposedFilterProperties}) — the short grammar pointer, then one line per exposed property with its
     * allowed values (where the base entity declares them) and, for a moment, the literal form the model writes.
     * A name the base entity does not know as a filter is left out: documenting it would promise a filter the query
     * refuses.
     *
     * @param class-string $requestDtoClassName The tool's request DTO carrying `#[DtoQueryOptions]`
     */
    public function narrowAllowedFilterPropertiesTo(MCPExposedFilterProperties $exposedFilterProperties, string $requestDtoClassName): void
    {
        if (!method_exists($requestDtoClassName, 'getDtoQueryOptions')) {
            return;
        }
        $filtersDefinitions = $requestDtoClassName::getDtoQueryOptions()?->getQueryOptions()?->getFiltersDefinitions();
        if ($filtersDefinitions === null) {
            return;
        }
        $description = FiltersOptions::getParameterSummary()
            . "\n\n<details><summary>Allowed filter properties:</summary>  \n\n";
        foreach ($exposedFilterProperties->propertyNames as $exposedPropertyName) {
            $filtersDefinition = $filtersDefinitions->getFilterDefinitionForPropertyName($exposedPropertyName);
            if ($filtersDefinition === null) {
                continue;
            }
            $description .= "\n- `{$exposedPropertyName}`";
            if ($filtersDefinition->options) {
                $description .= ' - one of [' . implode(', ', array_map(
                    static fn (mixed $option): string => '`' . $option . '`',
                    $filtersDefinition->options
                )) . ']';
            } elseif (($filtersDefinition->temporalKind ?? null) === FiltersDefinition::TEMPORAL_KIND_MOMENT) {
                $description .= ' - a moment, the ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . "'s local time `'YYYY-MM-DD HH:MM:SS'`";
            }
        }
        $this->description = $description . '</details>';
    }

    /**
     * @param string[] $hiddenQueryOptionFamilies QueryOptions families the OWNING TOOL METHOD hides from the agent doc
     *   via #[MCPHiddenQueryOptions] (filters/orderBy/expand/select). MCP-only; passed down from
     *   {@see MCPSchema::createFromReflectionMethod}. Empty for nested/recursive props (query options live only on the
     *   top-level request DTO).
     */
    public static function createFromReflectionProperty(
        MCPSchema $mcpSchema,
        ReflectionClass $reflectionClass,
        ReflectionProperty $reflectionProperty,
        bool $addObjectsToDef = true,
        int $maxDepth = 6,
        array $callPath = [],
        array $hiddenQueryOptionFamilies = []
    ): static|null
    {
        if ($maxDepth < 1) {
            return null;
        }
        $maxDepth--;
        // #[MCPHiddenQueryOptions] also accepts a plain PROPERTY NAME (e.g. 'skip') for the scalar paging params that
        // have no QueryOptions FAMILY type — for tools whose backing load does not honor them (listReviews' Argus load
        // pages by date only: a silently ignored skip returns the SAME page again and misleads the model into wrong
        // totals). Checked BEFORE the shared-parameter block so a shared skip/top is hidden too.
        if (in_array($reflectionProperty->getName(), $hiddenQueryOptionFamilies, true)) {
            return null;
        }
        // Shared, endpoint-INDEPENDENT framework params (outputFormat/noCache/skip/top/skiptoken — marked
        // #[SharedRequestParameter]) are documented ONCE in the system context (see SharedRequestParametersSyntax), not
        // per tool. Their rendering is identical on every tool → build once and reuse via a static cache (keyed by the
        // unique param name), so the per-turn tool-schema build never re-reads the same param across ~69 tools.
        if ($reflectionProperty->getAttributes(SharedRequestParameter::class)) {
            // Executor-controlled shared params (outputFormat) are hidden from the agent's tool schema entirely — the
            // model builds calls from the schema, so omitting it here removes its ability to set it across ALL tools.
            if (in_array($reflectionProperty->getName(), self::MCP_HIDDEN_SHARED_PARAMETERS, true)) {
                return null;
            }
            $mcpSchema->usesSharedParams = true;
            return self::$sharedParameterCache[$reflectionProperty->getName()]
                ??= self::buildSharedParameterProperty($reflectionProperty);
        }
        // Doc-only QueryOptions narrowing — omit a QueryOptions param (filters/orderBy/expand/select) from the schema
        // entirely (return null; the MCPSchema loop skips a null). The trait property stays present/hydrated/writable
        // (server code still sets it), only its DOC is dropped. Two independent, complementary triggers:
        //   1. #[DtoQueryOptions(expose: [...])] — narrows BOTH MCP and OpenApi (a doc contract for every consumer;
        //      Argus-only viability rule). See DtoQueryOptions::$expose.
        //   2. #[MCPHiddenQueryOptions(...)] ON THE TOOL METHOD — MCP-ONLY + per-endpoint: hides the family from THIS
        //      tool's agent doc while the REST/OpenApi surface (shared DTO, frontend) keeps it. The hidden families are
        //      read off the method by MCPSchema and threaded in via $hiddenQueryOptionFamilies. For tools that FORCE the
        //      option server-side, e.g. a tool `getBookings` that hard-codes its filter, orderBy and expand.
        $reflectionPropertyType = $reflectionProperty->getType();
        if ($reflectionPropertyType instanceof ReflectionNamedType) {
            $queryOptionFamily = DtoQueryOptions::queryOptionFamilyForTypeName($reflectionPropertyType->getName());
            if ($queryOptionFamily !== null
                && (!self::dtoExposesQueryOptionFamily($reflectionClass, $queryOptionFamily)
                    || in_array($queryOptionFamily, $hiddenQueryOptionFamilies, true))) {
                return null;
            }
        }
        $objectProperty = new static();
        $objectProperty->name = $reflectionProperty->getName();
        if ($docCommentText = $reflectionProperty->getDocComment()) {
            $docComment = new ReflectionDocComment($docCommentText, $reflectionClass, $reflectionProperty);
            $description = $docComment->getDescription(forProperty: true, includeExamples: false);
            $examples = $docComment->getExamples();
            if ($description && trim($description)) {
                $objectProperty->description = trim($description);
            }
            if ($examples) {
                $objectProperty->examples = $examples;
            }
        }

        $choiceValues = self::resolveChoiceValues($reflectionProperty);
        if ($choiceValues !== []) {
            $objectProperty->enum = $choiceValues;
        }

        $type = $reflectionProperty->getType();
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                $objectProperty->type = self::BUILTIN_TYPES_TO_NAMES_ALLOCATION[$type->getName()];
                if ($objectProperty->type == 'array') {
                    $arrayType = $type->getArrayType();
                    if ($arrayType) {
                        $objectProperty->items = new static();
                        if ($arrayType instanceof ReflectionNamedType) {
                            if ($arrayType->isBuiltin()) {
                                $objectProperty->items->type = self::BUILTIN_TYPES_TO_NAMES_ALLOCATION[$arrayType->getName(
                                )];
                            } else {
                                $objectProperty->items->addDefinitionOrProperties(
                                    $mcpSchema,
                                    $reflectionClass,
                                    $reflectionProperty,
                                    $arrayType,
                                    $addObjectsToDef,
                                    $maxDepth,
                                    $callPath
                                );
                            }
                        } elseif ($arrayType instanceof ReflectionUnionType) {
                            $arrayTypeOneOfTypes = $arrayType->getTypes();
                            $objectProperty->items->oneOf = [];
                            foreach ($arrayTypeOneOfTypes as $arrayTypeOneOfType) {
                                $arrayTypeOneOfProperty = new static();
                                if ($arrayTypeOneOfType->isBuiltin()) {
                                    $arrayTypeOneOfProperty->type = self::BUILTIN_TYPES_TO_NAMES_ALLOCATION[$arrayTypeOneOfType->getName(
                                    )];
                                } else {
                                    $arrayTypeOneOfProperty->items->addDefinitionOrProperties(
                                        $mcpSchema,
                                        $reflectionClass,
                                        $reflectionProperty,
                                        $arrayTypeOneOfType,
                                        $addObjectsToDef,
                                        $maxDepth,
                                        $callPath
                                    );
                                }
                                $objectProperty->items->oneOf[] = $arrayTypeOneOfProperty;
                            }
                        }
                    }
                }
            } elseif (
                is_a($type->getName(), ObjectSet::class, true)
                && DtoQueryOptions::queryOptionFamilyForTypeName($type->getName()) === null
            ) {
                // A plain (non-QueryOptions) ObjectSet/EntitySet documents as a JSON ARRAY of its element type — it
                // serializes as an array of its `elements`, not as a nested object with an `elements` property. The
                // element type is read from the set class's own `@var ElementType[] $elements` docblock (the items $ref).
                // QueryOptions ObjectSets (ExpandOptions/FiltersOptions/OrderByOptions/SelectOptions) are EXCLUDED: they
                // have their own string-grammar handling and their elements carry internal QueryOptions props that the
                // generic recursion would (wrongly) try to document.
                $objectProperty->type = 'array';
                $setReflectionClass = ReflectionClass::instance($type->getName());
                $elementsPropertyType = $setReflectionClass?->getProperty('elements')?->getType();
                $elementArrayType = ($elementsPropertyType instanceof ReflectionNamedType)
                    ? $elementsPropertyType->getArrayType()
                    : null;
                if ($elementArrayType instanceof ReflectionNamedType && !$elementArrayType->isBuiltin()) {
                    $objectProperty->items = new static();
                    $objectProperty->items->addDefinitionOrProperties(
                        $mcpSchema,
                        $reflectionClass,
                        $reflectionProperty,
                        $elementArrayType,
                        $addObjectsToDef,
                        $maxDepth,
                        $callPath
                    );
                }
            } else {
                $objectProperty->addDefinitionOrProperties(
                    $mcpSchema,
                    $reflectionClass,
                    $reflectionProperty,
                    $type,
                    $addObjectsToDef,
                    $maxDepth,
                    $callPath
                );
            }
        } elseif ($type instanceof ReflectionUnionType) {
            $types = $type->getTypes();
            $allTypesAreBuiltin = true;
            $allTypesAreComplex = true;
            $objectProperty->type = [];
            $objectProperty->oneOf = [];
            foreach ($types as $type) {
                if ($type->getName() != 'null') {
                    $allTypesAreBuiltin = $allTypesAreBuiltin && $type->isBuiltin();
                    $allTypesAreComplex = $allTypesAreComplex && !$type->isBuiltin();
                }
                if ($type->isBuiltin()) {
                    $objectProperty->type[] = self::BUILTIN_TYPES_TO_NAMES_ALLOCATION[$type->getName()];
                } else {
                    $unionTypeOneOfProperty = new static();
                    $unionTypeOneOfProperty->addDefinitionOrProperties(
                        $mcpSchema,
                        $reflectionClass,
                        $reflectionProperty,
                        $type,
                        $addObjectsToDef,
                        $maxDepth,
                        $callPath
                    );
                    $objectProperty->oneOf[] = $unionTypeOneOfProperty;
                }
            }
            if (!($allTypesAreBuiltin || $allTypesAreComplex)) {
                throw new TypeDefinitionMissingOrWrong(
                    "Mixture of builtin types and complex types is not allowed in {$reflectionClass->getName()}->\${$reflectionProperty->getName()}"
                );
            }
            if ($allTypesAreBuiltin) {
                unset($objectProperty->oneOf);
            } elseif ($allTypesAreComplex) {
                unset($objectProperty->type);
            }
        }
        return $objectProperty;
    }

    public function addDefinitionOrProperties(
        MCPSchema $mcpSchema,
        ReflectionClass $reflectionClass,
        ReflectionProperty $referencedProperty,
        ReflectionNamedType $namedTypeOnProperty,
        bool $addObjectsToDef = true,
        int $maxDepth = 4,
        array $callPath = [],
    ): void
    {
        $propertyClass = new ClassWithNamespace($namedTypeOnProperty->getName());
        if (!class_exists($propertyClass->getNameWithNamespace())) {
            throw new TypeDefinitionMissingOrWrong(
                "Declared type class {$propertyClass->getNameWithNamespace()} in {$reflectionClass->getName()}->\${$referencedProperty->getName()} does not exist"
            );
        }
        $namedTypeReflectionClass = ReflectionClass::instance($namedTypeOnProperty->getName());
        
        // DateTime and Date are handled with custom format documentation
        $typeName = $namedTypeOnProperty->getName();
        if ($typeName === DateTime::class || $typeName === Date::class) {
            $this->type = 'string';
            
            if ($typeName === Date::class) {
                // Date format: Y-m-d. A fixed example: the day of the call would change the tool schema at
                // midnight and with it every cached prompt prefix that carries the tool list.
                $formatDescription = 'Date in ISO 8601 format (YYYY-MM-DD)';
                $example = '2026-03-14';
            } else {
                // DateTime format: Y-m-d H:i:s — a wall-clock time on the conversation's clock; a fixed example
                // keeps the tool schema byte-stable across calls (prompt cache).
                $formatDescription = 'Wall-clock date-time as stated in the context\'s time rule, format YYYY-MM-DD HH:MM:SS; an offset suffix (e.g. +02:00) is honoured';
                $example = '2026-03-14 09:30:00';
            }
            
            // Append format info to description
            if (isset($this->description) && $this->description !== '') {
                $this->description .= "\n\n" . $formatDescription;
            } else {
                $this->description = $formatDescription;
            }
            
            // Add example if not already set
            if (!isset($this->examples) || empty($this->examples)) {
                $this->examples = [$example];
            }
            
            return;
        }
        
        if ($namedTypeReflectionClass->hasMethod('fromString')) {
            // Check if the Property has a fromString function,
            // This is given e.g. for Filters, expand etc.
            // We set string type and optionally augment documentation.
            $this->type = 'string';
            // Flag the owning schema as exposing QueryOptions, so the agent loop can emit the shared QueryOptions
            // grammar ONCE in the system context (instead of inlining it into every parameter). The flag is set here —
            // during doc-gen, where the type is still known — because the built wire schema collapses these to
            // type:'string' and the QueryOptions-ness is no longer detectable downstream.
            if (in_array($typeName, [FiltersOptions::class, OrderByOptions::class, ExpandOptions::class, SelectOptions::class], true)) {
                $mcpSchema->usesQueryOptions = true;
            }
            $this->appendQueryOptionsDocumentationIfApplicable(
                schemaReflectionClass: $reflectionClass,
                referencedProperty: $referencedProperty,
                typeName: $namedTypeOnProperty->getName()
            );
            return;
        }
        if ($addObjectsToDef) {
            $definitionIndex = $propertyClass->getNameWithNamespace('/');
            $this->ref = '#/$defs/' . $definitionIndex;

            if (!$mcpSchema->hasObjectDefinitionForIndex($definitionIndex)) {
                $objectDefinition = MCPObjectDefinition::createFromClassName(
                    $namedTypeOnProperty->getName(),
                    $mcpSchema,
                    $addObjectsToDef,
                    $maxDepth,
                    $callPath
                );
                if ($objectDefinition) {
                    $mcpSchema->addObjectDefinitionForIndex($definitionIndex, $objectDefinition);
                } else {
                    unset($this->ref);
                    $this->type = 'object';
                    if (isset($this->description)) {
                        $this->description .= ' - Truncated by maxDepth';
                    } else {
                        $this->description = 'Truncated by maxDepth';
                    }
                }
            }
        } else {
            $this->type = 'object';
            if ($maxDepth < 1) {
                if (isset($this->description)) {
                    $this->description .= ' - Truncated by maxDepth';
                } else {
                    $this->description = 'Truncated by maxDepth';
                }
                return;
            }
            foreach ($namedTypeReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
                if ($reflectionProperty->hasAttribute(HideProperty::class) || $reflectionProperty->isStatic()
                    || $mcpSchema->omitsOutputOnlyProperty($reflectionProperty)) {
                    continue;
                }
                if (!isset($this->properties)) {
                    $this->properties = [];
                }
                if ($reflectionProperty->hasAttribute(
                    SerializeInToonFormat::class,
                    ReflectionAttribute::IS_INSTANCEOF
                )) {
                    // In case we have a property that is enabled to be serialized in Toon format, we add an additional property
                    // with a postfix e.g. when property name is "elements", we create  "elementsInToonFormat" of type string in addition to the original property
                    $toonObjectProperty = new MCPObjectProperty();
                    $toonObjectProperty->name = SerializeInToonFormat::getToonPropertyName($reflectionProperty->getName());
                    $toonObjectProperty->type = 'string';
                    $toonObjectProperty->description = "Token-Oriented Object Notation (TOON) representation of property '{$reflectionProperty->getName()}', if set, original property will be omitted.";
                    $this->properties[$toonObjectProperty->name] = $toonObjectProperty;
                }
                $mcpObjectProperty = MCPObjectProperty::createFromReflectionProperty(
                    $mcpSchema,
                    $namedTypeReflectionClass,
                    $reflectionProperty,
                    $addObjectsToDef,
                    $maxDepth,
                    $callPath
                );
                // it is possible that property is null due to maxDepth
                if ($mcpObjectProperty) {
                    $this->properties[$reflectionProperty->getName()] = $mcpObjectProperty;
                }
                if (empty($this->properties)) {
                    unset($this->properties);
                }
            }
        }
    }

    protected function appendQueryOptionsDocumentationIfApplicable(
        ReflectionClass $schemaReflectionClass,
        ReflectionProperty $referencedProperty,
        string $typeName
    ): void
    {
        if (!in_array($typeName, [FiltersOptions::class, OrderByOptions::class, ExpandOptions::class], true)) {
            return;
        }

        $schemaReflectionClassName = $schemaReflectionClass->getName();

        // If a DTO uses QueryOptions (Filters/OrderBy/Expand) it must use DtoQueryOptionsTrait + have a DtoQueryOptions attribute.
        if (!method_exists($schemaReflectionClassName, 'getDtoQueryOptions')) {
            throw new TypeDefinitionMissingOrWrong(
                "Class $schemaReflectionClassName uses QueryOptions types on \${$referencedProperty->getName()} but does not expose getDtoQueryOptions(). Ensure it uses DtoQueryOptionsTrait."
            );
        }

        /** @var DtoQueryOptionsTrait $schemaReflectionClassName */
        $dtoQueryOptions = $schemaReflectionClassName::getDtoQueryOptions();
        if (!$dtoQueryOptions) {
            throw new TypeDefinitionMissingOrWrong(
                'Class ' . $schemaReflectionClassName . ' uses QueryOptions without having an attribute of type ' . DtoQueryOptions::class
            );
        }

        $queryOptions = $dtoQueryOptions->getQueryOptions();
        if (!$queryOptions) {
            throw new TypeDefinitionMissingOrWrong(
                "Class $schemaReflectionClassName uses QueryOptions with a reference to base entity $dtoQueryOptions->baseEntity, but base entity has no QueryOptions attribute set on class"
            );
        }

        // Reset the PHPDoc-derived description to the short, endpoint-independent summary — the full grammar is emitted
        // ONCE in the agent's system context (AgentModelInput, gated by usesQueryOptions). Then append this endpoint's
        // allowed-property list (unchanged, incl. its custom option values). Early-returns below (no definitions) leave
        // the shrunk one-line PHPDoc description in place, which still carries the "QueryOptions syntax" trigger phrase.
        if ($typeName === FiltersOptions::class) {
            $filtersDefinitions = $queryOptions->getFiltersDefinitions();
            if (!($filtersDefinitions && $filtersDefinitions->count())) {
                return;
            }

            $this->description = FiltersOptions::getParameterSummary();
            $this->description .= "\n\n<details><summary>Allowed filter properties:</summary>  \n\n";
            $queryOptionsBaseReflectionClass = ReflectionClass::instance($dtoQueryOptions->baseEntity);
            $constantDescriptions = $queryOptionsBaseReflectionClass->getConstantsDescriptions();

            foreach ($filtersDefinitions->getElements() as $allowedField) {
                $constantDescription = $constantDescriptions[$allowedField->propertyName] ?? '';
                $this->description .= "\n- `$allowedField->propertyName`" . ($constantDescription ? ': ' . $constantDescription : '');
                if ($allowedField->options) {
                    $this->description .= ' - one of [';
                    $i = 0;
                    foreach ($allowedField->options as $option) {
                        $this->description .= ($i ? ', ' : '') . '`' . $option . '`';
                        $i++;
                    }
                    $this->description .= ']';
                }
            }
            $this->description .= '</details>';
            return;
        }

        if ($typeName === OrderByOptions::class) {
            $orderByDefinitions = $queryOptions->getOrderByDefinitions();
            if (empty($orderByDefinitions)) {
                return;
            }

            $this->description = OrderByOptions::getParameterSummary();
            // Top-level orderBy mirrors the filter property names for structural entities (orderBy is derived from the
            // filter names) — don't repeat the identical list under both params; reference `filters`. Render the list
            // only when it diverges (custom #[QueryOptions(orderBy: …)] keys), so those custom keys are still shown.
            $filterPropertyNames = [];
            $filtersDefinitions = $queryOptions->getFiltersDefinitions();
            if ($filtersDefinitions) {
                foreach ($filtersDefinitions->getElements() as $allowedField) {
                    $filterPropertyNames[] = $allowedField->propertyName;
                }
            }
            if ($filterPropertyNames !== [] && self::isSamePropertySet($filterPropertyNames, $orderByDefinitions)) {
                $this->description .= "\n\nSortable by the same properties as the `filters` parameter.";
                return;
            }
            $this->description .= "\n\n<details><summary>Allowed orderBy properties:</summary>  \n\n";
            foreach ($orderByDefinitions as $allowedField) {
                $this->description .= "\n- `$allowedField`";
            }
            $this->description .= '</details>';
            return;
        }

        if ($typeName === ExpandOptions::class) {
            try {
                $expandDefinitions = ExpandDefinitions::getExpandDefinitionsForReferenceClass(
                    $dtoQueryOptions->baseEntity
                );
            } catch (ReflectionException $e) {
                throw new TypeDefinitionMissingOrWrong(
                    "Failed to resolve ExpandDefinitions for base entity $dtoQueryOptions->baseEntity: {$e->getMessage()}"
                );
            }

            if (!($expandDefinitions && $expandDefinitions->count())) {
                return;
            }

            $this->description = ExpandOptions::getParameterSummary();
            $this->description .= "\n\n<details><summary>Allowed expand properties:</summary>  \n\n";
            // MCP renders the expandable RELATION NAMES only — NOT the per-relation nested filter/orderBy property lists.
            // Rationale: the agent practically never writes a nested expand clause (`expand=relation(filters=…)`), so
            // those sub-lists are dead per-turn weight here; the relation list alone preserves the expand capability
            // (`expand=replies`). The FULL nested form stays in the OpenApi documenter (PathParameterSchema) for
            // REST/SDK consumers that DO use nested clauses — a deliberate MCP-vs-OpenApi divergence. If the agent ever
            // needs a relation's filterable fields, the once-emitted "QueryOptions syntax" + that relation's own tool cover it.
            foreach ($expandDefinitions->getElements() as $expandDefinition) {
                $this->description .= "\n- `$expandDefinition->propertyName`";
            }
            $this->description .= '</details>';
            // ── DISABLED FOR MCP (kept for reference, NOT dead — the LIVE copy of this is OpenApi PathParameterSchema) ──
            // The former per-relation nested filter/orderBy rendering. Re-enable here ONLY if the agent ever needs to
            // write nested expand clauses (`expand=rel(filters=…;orderBy=…)`) — today it never does, so these sub-lists
            // were pure per-turn weight (~440→~80 tok/read). It includes the set-equality dedup (render "filter & orderBy"
            // once when the sets match; both separately on a custom #[QueryOptions(orderBy:…)] divergence, e.g.
            // a set that filters on a column and orders by keys of its own — so no custom orderBy key is lost):
            /*
            foreach ($expandDefinitions->getElements() as $expandDefinition) {
                $this->description .= "\n- `$expandDefinition->propertyName`";
                $filtersDefinitions = $expandDefinition->getFiltersDefinitions();
                $orderByDefinitions = $expandDefinition->getOrderbyDefinitions() ?? [];
                $filterPropertyNames = [];
                if ($filtersDefinitions) {
                    foreach ($filtersDefinitions->getElements() as $allowedField) {
                        $filterPropertyNames[] = $allowedField->propertyName;
                    }
                }
                $orderBySameAsFilters = $filterPropertyNames !== []
                    && self::isSamePropertySet($filterPropertyNames, $orderByDefinitions);
                if ($filtersDefinitions) {
                    $this->description .= $orderBySameAsFilters
                        ? "\n  - Allowed filter & orderBy properties are:"
                        : "\n  - Allowed filter properties are:";
                    foreach ($filtersDefinitions->getElements() as $allowedField) {
                        $this->description .= "\n    - `$allowedField->propertyName`";
                        if ($allowedField->options) {
                            $this->description .= ': one of [';
                            $i = 0;
                            foreach ($allowedField->options as $option) {
                                $this->description .= ($i ? ', ' : '') . '`' . $option . '`';
                                $i++;
                            }
                            $this->description .= ']';
                        }
                    }
                }
                if (!$orderBySameAsFilters && $orderByDefinitions !== []) {
                    $this->description .= "\n  - Allowed orderBy properties are:";
                    foreach ($orderByDefinitions as $allowedField) {
                        $this->description .= "\n    - `$allowedField`";
                    }
                }
            }
            */
        }
    }

    /**
     * Builds the minimal schema entry for a #[SharedRequestParameter] — name + JSON type + a one-line pointer to the
     * once-emitted "Shared request parameters" block (no per-tool description). Endpoint-invariant, so the result is
     * cached by {@see self::createFromReflectionProperty()} and reused across all tools.
     */
    protected static function buildSharedParameterProperty(ReflectionProperty $reflectionProperty): static
    {
        $objectProperty = new static();
        $objectProperty->name = $reflectionProperty->getName();
        $type = $reflectionProperty->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'string';
        $objectProperty->type = self::BUILTIN_TYPES_TO_NAMES_ALLOCATION[$typeName] ?? 'string';
        // No inline description (omitted from the wire) — these params are documented once in the system context's
        // "Shared request parameters" block, which lists each by name. Keeps the per-tool schema minimal.
        $objectProperty->description = null;
        return $objectProperty;
    }

    /**
     * True if both lists contain the SAME set of property names (order- and duplicate-insensitive). Used to dedup the
     * expand block's filter vs orderBy sub-lists: only collapse them when they are genuinely identical sets, so custom
     * orderBy-only keys (or filter-only virtual/fulltext columns) are never silently dropped.
     *
     * @param string[] $a
     * @param string[] $b
     */
    /** Whether the owning DTO's `#[DtoQueryOptions(expose:)]` renders this QueryOptions family (true if not a QueryOptions DTO, or no narrowing). */
    protected static function dtoExposesQueryOptionFamily(ReflectionClass $ownerReflectionClass, string $family): bool
    {
        $ownerClass = $ownerReflectionClass->getName();
        if (!method_exists($ownerClass, 'getDtoQueryOptions')) {
            return true;
        }
        $dtoQueryOptions = $ownerClass::getDtoQueryOptions();
        return $dtoQueryOptions === null || $dtoQueryOptions->exposes($family);
    }

    protected static function isSamePropertySet(array $a, array $b): bool
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        if (count($a) !== count($b)) {
            return false;
        }
        sort($a);
        sort($b);
        return $a === $b;
    }
}