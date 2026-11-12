<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\SerializeInToonFormat;
use ReflectionAttribute;

/** @phpstan-consistent-constructor */
class MCPObjectDefinition extends ValueObject
{
    public const int DEFAULT_RECURSION_DEPTH = 3;

    public string $type = 'object';

    public array $required;

    /** @var MCPObjectProperty[] Properties of object */
    public array $properties;

    #[HideProperty]
    public ?string $objectType;

    public static function createFromClassName(
        string $className,
        MCPSchema $mcpSchema,
        bool $addObjectsToDef = false,
        int $maxDepth = 3,
        array $callPath = [],
    ): static|null
    {
        if (isset($callPath[$className])) {
            return null;
        }
        $callPath[$className] = true;
        if ($maxDepth < 1) {
            return null;
        }
        $objectReference = new static();
        $reflectionClass = ReflectionClass::instance($className);
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            if ($reflectionProperty->hasAttribute(HideProperty::class) || $reflectionProperty->isStatic()
                || $mcpSchema->omitsOutputOnlyProperty($reflectionProperty)) {
                continue;
            }
            if (!isset($objectReference->properties)) {
                $objectReference->properties = [];
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
                $toonObjectProperty->description =
                    "TOON (Token-Oriented Object Notation) for '{$reflectionProperty->getName()}'. "
                    . "Format: `[N](col1,col2,...):\\nval1,val2,...\\n...` (N=row count; rows newline-separated; values comma-separated in header order). "
                    . "If present, '{$reflectionProperty->getName()}' is omitted.";
                $objectReference->properties[$toonObjectProperty->name] = $toonObjectProperty;
            }
            $mcpObjectProperty = MCPObjectProperty::createFromReflectionProperty(
                $mcpSchema,
                $reflectionClass,
                $reflectionProperty,
                $addObjectsToDef,
                $maxDepth,
                $callPath
            );
            // it is possible that property is null due to maxDepth
            if ($mcpObjectProperty) {
                $objectReference->properties[$reflectionProperty->getName()] = $mcpObjectProperty;
            }
        }
        return $objectReference;
    }
}