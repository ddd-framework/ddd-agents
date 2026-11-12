<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * No class of the package builds a database entity of the package, the set of one, or a repository of either with
 * `new`.
 *
 * An application may put a subclass of such an entity at the same path under its own root namespace (with a column
 * of its own). `X::newInstance()` gives the application's class then; `new X()` gives the package's class, and an
 * object of the package's class is written through the package's model — the application's column is not written, or
 * the insert fails without an error reaching the caller. The same holds for the set, which carries the application's
 * service and repository, and for the repository: `new DBX()` reads through the package's model and skips the read
 * rights the application's repository adds; `X::getRepoClassInstance()` and the service's
 * `getEntityRepoClassInstance()` / `getEntitySetRepoClassInstance()` give the application's repository.
 */
class DatabaseEntitiesAreNeverBuiltWithNewTest extends UnitTestCase
{
    public function testNoPackageClassBuildsADatabaseEntityItsSetOrARepositoryOfThemWithNew(): void
    {
        $shortClassNames = $this->findShortNamesOfDatabaseEntitiesTheirSetsAndRepositories();
        self::assertGreaterThanOrEqual(52, count($shortClassNames), 'thirteen database entities, their sets and the repositories of both');
        $newExpressionPattern = '/\bnew\s+\\\\?(?:[A-Za-z_]+\\\\)*(' . implode('|', array_map('preg_quote', $shortClassNames)) . ')\s*\(/';

        $filesBuildingWithNew = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            if (preg_match_all($newExpressionPattern, (string)file_get_contents($absolutePath), $matches) > 0) {
                $filesBuildingWithNew[] = $relativePath . ': new ' . implode(', new ', array_unique($matches[1]));
            }
        }

        self::assertSame(
            [],
            $filesBuildingWithNew,
            'use X::newInstance() / X::getRepoClassInstance(): an application may subclass X and its repository at the same path'
        );
    }

    /**
     * @return string[] The short class names of the package's database entities, of their sets and of the
     *                  repositories of both, longest first
     */
    protected function findShortNamesOfDatabaseEntitiesTheirSetsAndRepositories(): array
    {
        $packageSourceDirectory = (string)realpath(dirname(__DIR__, 2) . '/src');
        $shortClassNames = [];
        foreach (EntityModelGeneratorService::getAllEntityClasses() as $classWithNamespace) {
            $entityClass = $classWithNamespace->getNameWithNamespace();
            $entityReflection = new ReflectionClass($entityClass);
            if (!str_starts_with((string)$entityReflection->getFileName(), $packageSourceDirectory . '/')) {
                continue;
            }
            $classesNeverBuiltWithNew = [$entityClass, $entityClass::getRepoClass()];
            $entitySetClass = $entityClass::getEntitySetClass();
            if (is_string($entitySetClass) && $entitySetClass !== '') {
                $classesNeverBuiltWithNew[] = $entitySetClass;
                $classesNeverBuiltWithNew[] = $entitySetClass::getRepoClass();
            }
            foreach ($classesNeverBuiltWithNew as $classNeverBuiltWithNew) {
                if (is_string($classNeverBuiltWithNew) && $classNeverBuiltWithNew !== '') {
                    $shortClassNames[] = (new ReflectionClass($classNeverBuiltWithNew))->getShortName();
                }
            }
        }
        $shortClassNames = array_values(array_unique($shortClassNames));
        usort($shortClassNames, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));
        return $shortClassNames;
    }
}
