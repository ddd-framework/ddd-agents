<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * A `#[LazyLoad]` without a repo type takes the APPLICATION's default (`LAZYLOAD_DEFAULT_REPO_TYPE`), and an
 * application may set that to a repo type the package has no repository for (a legacy database, for example). A
 * relation of the package to one of ITS OWN entities must then still find the repository the package ships: reading
 * the relation without one fails with "Class name must be a valid object or a string" on every access.
 *
 * So every such relation names its repo type. The test resolves each of them the way the framework does (the
 * attribute's repository, else the repository of `entityClassName`, else the repository of the declared type) while
 * the default repo type is one the package ships no repository for.
 *
 * A relation to a class of ANOTHER package (the account) is deliberately left to the application's default: its
 * entity may live in a repository of the application's own.
 */
class RelationsToPackageEntitiesResolveARepositoryTest extends UnitTestCase
{
    public function testEveryRelationToAPackageEntityResolvesARepositoryUnderAForeignDefaultRepoType(): void
    {
        $defaultRepoTypeBefore = LazyLoadRepo::getDafaultRepoType();
        LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::LEGACY_DB);
        try {
            $relationsWithoutRepository = [];
            $checkedRelationsCount = 0;
            foreach (AgentPackageSourceRoots::findPhpFiles('Domain/AI/Entities') as $relativePath => $absolutePath) {
                $className = 'DDD\\' . str_replace('/', '\\', substr($relativePath, 0, -4));
                if (!class_exists($className)) {
                    continue;
                }
                foreach ((new ReflectionClass($className))->getProperties() as $reflectionProperty) {
                    if ($reflectionProperty->getDeclaringClass()->getName() !== $className) {
                        continue;
                    }
                    $relatedClassName = static::findRelatedPackageClassName($reflectionProperty);
                    if ($relatedClassName === null) {
                        continue;
                    }
                    foreach ($reflectionProperty->getAttributes(LazyLoad::class, ReflectionAttribute::IS_INSTANCEOF) as $lazyLoadAttribute) {
                        /** @var LazyLoad $lazyLoad */
                        $lazyLoad = $lazyLoadAttribute->newInstance();
                        if ($lazyLoad->repoType === LazyLoadRepo::CLASS_METHOD) {
                            continue;
                        }
                        $checkedRelationsCount++;
                        if (static::findRepositoryClassName($lazyLoad, $relatedClassName) === null) {
                            $relationsWithoutRepository[] = $className . '::$' . $reflectionProperty->getName()
                                . ' (repo type ' . $lazyLoad->repoType . ', related ' . $relatedClassName . ')';
                        }
                    }
                }
            }
        } finally {
            LazyLoadRepo::setDafaultRepoType($defaultRepoTypeBefore);
        }

        self::assertSame([], $relationsWithoutRepository, 'a relation to a package entity names the repo type the package ships a repository for');
        self::assertGreaterThan(10, $checkedRelationsCount, 'the sweep reads the relations of the package entities');
    }

    /**
     * The class a lazily loaded property relates to, when that class is one of this package and can name a
     * repository; null for a scalar, a class of another package, or a class without repositories.
     *
     * @param ReflectionProperty $reflectionProperty
     * @return string|null
     */
    protected static function findRelatedPackageClassName(ReflectionProperty $reflectionProperty): ?string
    {
        $propertyType = $reflectionProperty->getType();
        if (!$propertyType instanceof ReflectionNamedType || $propertyType->isBuiltin()) {
            return null;
        }
        $relatedClassName = $propertyType->getName();
        if (!class_exists($relatedClassName) || !method_exists($relatedClassName, 'getRepoClass')) {
            return null;
        }
        $relatedClassFile = (string)(new ReflectionClass($relatedClassName))->getFileName();
        if (!str_starts_with($relatedClassFile, AgentPackageSourceRoots::getPackageSourceDirectory() . '/')) {
            return null;
        }
        return $relatedClassName;
    }

    /**
     * The repository a relation loads from, in the framework's order: the attribute's own repository, else the one of
     * `entityClassName`, else the one of the declared type — each for the attribute's repo type.
     *
     * @param LazyLoad $lazyLoad
     * @param string $relatedClassName
     * @return string|null
     */
    protected static function findRepositoryClassName(LazyLoad $lazyLoad, string $relatedClassName): ?string
    {
        if ($lazyLoad->repoClass) {
            return $lazyLoad->repoClass;
        }
        $entityClassName = $lazyLoad->entityClassName ?: $relatedClassName;
        if (!method_exists($entityClassName, 'getRepoClass')) {
            return null;
        }
        return $entityClassName::getRepoClass($lazyLoad->repoType);
    }
}
