<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewFolderClassFinder;
use LogicException;

/**
 * The entity type → resolver registry: every concrete {@see EntityReferenceResolver} in the AI
 * core's `Widgets/Composed/EntityReferences/` folder and every domain's `Entities/AI/Conversations/EntityReferences/`
 * folder (any depth), keyed by `ENTITY_TYPE`, built once per process — a lane adds an entity type with one new file.
 */
class EntityReferenceResolverClasses
{
    public const string RESOLVER_CLASS_SHORT_NAME_SUFFIX = 'EntityReferenceResolver';

    /** @var array<string, class-string<EntityReferenceResolver>>|null */
    protected static ?array $resolverClassesByEntityType = null;

    /** @return class-string<EntityReferenceResolver>|null */
    public static function getResolverClassForEntityType(string $entityType): ?string
    {
        return static::getResolverClassesByEntityType()[$entityType] ?? null;
    }

    /** @return string[] every registered entity type, sorted */
    public static function getEntityTypes(): array
    {
        return array_keys(static::getResolverClassesByEntityType());
    }

    /** @return array<string, class-string<EntityReferenceResolver>> */
    public static function getResolverClassesByEntityType(): array
    {
        return static::$resolverClassesByEntityType ??= static::buildResolverClassesByEntityType(...ComposedViewFolderClassFinder::findClassNamesBelowFolders(
            self::RESOLVER_CLASS_SHORT_NAME_SUFFIX,
            [__DIR__, ...ComposedViewFolderClassFinder::findDomainFolders('Entities/AI/Conversations/EntityReferences')],
        ));
    }

    /**
     * Replaces the registry (a test with stub types); null rebuilds it from the folders on the next use.
     *
     * @param array<string, class-string<EntityReferenceResolver>>|null $resolverClassesByEntityType
     */
    public static function replaceResolverClasses(?array $resolverClassesByEntityType): void
    {
        static::$resolverClassesByEntityType = $resolverClassesByEntityType;
    }

    /**
     * @return array<string, class-string<EntityReferenceResolver>>
     * @throws LogicException only under tests: a duplicate type, a misnamed class or an invalid type
     */
    public static function buildResolverClassesByEntityType(string ...$classNames): array
    {
        $resolverClassesByEntityType = [];
        foreach (ComposedViewFolderClassFinder::filterConcreteSubclasses(EntityReferenceResolver::class, ...$classNames) as $className) {
            $entityType = $className::ENTITY_TYPE;
            if (isset($resolverClassesByEntityType[$entityType])) {
                // an application or module subclass of a discovered resolver replaces it (override before duplicate)
                $overridingClassName = ComposedViewFolderClassFinder::resolveOverridingClassName($resolverClassesByEntityType[$entityType], $className);
                if ($overridingClassName !== null) {
                    $resolverClassesByEntityType[$entityType] = $overridingClassName;
                    continue;
                }
            }
            if (preg_match('/^[a-z][A-Za-z0-9]*$/', $entityType) !== 1 || isset($resolverClassesByEntityType[$entityType])) {
                ComposedViewFolderClassFinder::handleBuildError(sprintf('The resolver %s has an invalid or duplicate ENTITY_TYPE "%s".', $className, $entityType));
                continue;
            }
            if (!str_ends_with($className, '\\' . ucfirst($entityType) . self::RESOLVER_CLASS_SHORT_NAME_SUFFIX)) {
                ComposedViewFolderClassFinder::handleBuildError(sprintf('The resolver %s must be named "%s%s".', $className, ucfirst($entityType), self::RESOLVER_CLASS_SHORT_NAME_SUFFIX));
                continue;
            }
            $resolverClassesByEntityType[$entityType] = $className;
        }
        ksort($resolverClassesByEntityType);
        return $resolverClassesByEntityType;
    }
}
