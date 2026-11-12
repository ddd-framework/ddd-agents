<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * Regenerating the package's Doctrine models under the package kernel yields exactly the committed `DB*Model.php`
 * files (generated in memory, nothing is written), and every committed model has an entity.
 *
 * The committed models are generated with an empty `DATABASE_TABLE_PREFIX` ({@see self::SHIPPED_MODELS_TABLE_PREFIX}),
 * the convention for a framework package: a consuming application with a table prefix regenerates the models with its
 * own prefix. No entity of the package carries a table name of its own: an application that keeps other names lists
 * them in its configuration (`tableNames` in `config/app/Base/Repo/database.php`), a file the package never ships.
 */
class DoctrineModelsRegenerationTest extends UnitTestCase
{
    /** @var string The table prefix the committed models carry: none */
    public const string SHIPPED_MODELS_TABLE_PREFIX = '';

    /** @var int Package entities with a DB repository, each with one committed model */
    public const int PACKAGE_DB_ENTITY_COUNT = 13;

    protected ?string $tablePrefixBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tablePrefixBefore = $_ENV['DATABASE_TABLE_PREFIX'] ?? null;
        $_ENV['DATABASE_TABLE_PREFIX'] = self::SHIPPED_MODELS_TABLE_PREFIX;
    }

    protected function tearDown(): void
    {
        if ($this->tablePrefixBefore === null) {
            unset($_ENV['DATABASE_TABLE_PREFIX']);
        } else {
            $_ENV['DATABASE_TABLE_PREFIX'] = $this->tablePrefixBefore;
        }
        parent::tearDown();
    }

    public function testEveryCommittedModelIsWhatTheGeneratorProducesForItsEntity(): void
    {
        $packageSourceDirectory = (string)realpath(dirname(__DIR__, 2) . '/src');
        $regeneratedModelFiles = [];
        $differingModelFiles = [];
        foreach (EntityModelGeneratorService::getAllEntityClasses() as $classWithNamespace) {
            $entityClass = $classWithNamespace->getNameWithNamespace();
            $entityFile = (string)(new ReflectionClass($entityClass))->getFileName();
            if (!str_starts_with($entityFile, $packageSourceDirectory . '/')) {
                continue;
            }
            $databaseModel = EntityModelGeneratorService::getDatabaseModels([$entityClass])->first();
            if ($databaseModel === null) {
                continue;
            }
            $modelFile = $databaseModel->modelClassWithNamespace->filename;
            $relativeModelFile = substr($modelFile, strlen($packageSourceDirectory) + 1);
            $regeneratedModelFiles[] = $relativeModelFile;
            if (!is_file($modelFile) || file_get_contents($modelFile) !== $databaseModel->getDoctrineModelCode()) {
                $differingModelFiles[] = $relativeModelFile;
            }
        }
        self::assertSame([], $differingModelFiles, 'regenerated model differs from the committed file');
        self::assertCount(self::PACKAGE_DB_ENTITY_COUNT, $regeneratedModelFiles);

        $committedModelFiles = $this->findCommittedModelFiles($packageSourceDirectory);
        self::assertSame([], array_values(array_diff($committedModelFiles, $regeneratedModelFiles)), 'committed model without an entity');
    }

    public function testNoPackageEntityCarriesATableNameOfItsOwnAndThePackageShipsNoTableConfiguration(): void
    {
        $packageDirectory = (string)realpath(dirname(__DIR__, 2));
        $entityClassesWithATableNameOfTheirOwn = [];
        $checkedEntityCount = 0;
        foreach (EntityModelGeneratorService::getAllEntityClasses() as $classWithNamespace) {
            $entityClass = $classWithNamespace->getNameWithNamespace();
            $entityFile = (string)(new ReflectionClass($entityClass))->getFileName();
            if (!str_starts_with($entityFile, $packageDirectory . '/src/')) {
                continue;
            }
            $databaseModel = EntityModelGeneratorService::getDatabaseModels([$entityClass])->first();
            if ($databaseModel === null) {
                continue;
            }
            $checkedEntityCount++;
            $entitySetShortName = substr((string)strrchr('\\' . $entityClass::getEntitySetClass(), '\\'), 1);
            if ($databaseModel->sqlTableName !== self::SHIPPED_MODELS_TABLE_PREFIX . $entitySetShortName) {
                $entityClassesWithATableNameOfTheirOwn[] = $entityClass . ' → ' . $databaseModel->sqlTableName;
            }
        }
        self::assertSame([], $entityClassesWithATableNameOfTheirOwn, 'a table name other than prefix + entity set name is the application\'s to give');
        self::assertSame(self::PACKAGE_DB_ENTITY_COUNT, $checkedEntityCount);
        self::assertFileDoesNotExist($packageDirectory . '/config/app/Base/Repo/database.php', 'the application\'s file and a shipped one would shadow each other');
    }

    /** @return string[] Every `DB*Model.php` below src/, relative to src/, sorted */
    protected function findCommittedModelFiles(string $packageSourceDirectory): array
    {
        $committedModelFiles = [];
        $fileIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($packageSourceDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $file) {
            if ($file->isFile() && preg_match('~/DB[^/]+Model\.php$~', $file->getPathname()) === 1) {
                $committedModelFiles[] = substr($file->getPathname(), strlen($packageSourceDirectory) + 1);
            }
        }
        sort($committedModelFiles);
        return $committedModelFiles;
    }
}
