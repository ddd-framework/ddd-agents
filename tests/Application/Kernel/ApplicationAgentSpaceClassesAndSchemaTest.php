<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Application\Kernel;

use App\Domain\AI\Entities\AgentSpaces\AgentSpace;
use App\Domain\AI\Entities\AgentSpaces\AgentSpaces;
use App\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace;
use App\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use App\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use App\Domain\Workshops\Entities\Workshops\Workshop;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\PackageTestEnvironment;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * An application that subclasses the space (the fixture application), without a database:
 *
 * - the package's class names resolve to the application's classes — the service the agent core asks, a new
 *   instance, the repository;
 * - the space has ONE table, defined by the application's entity: the package's columns plus the application's
 *   required, unique reference, deleted together with the application's entity;
 * - the package's model and the application's model name that same table;
 * - the committed models of the fixture application are what the generator produces.
 */
class ApplicationAgentSpaceClassesAndSchemaTest extends UnitTestCase
{
    /** @var string The table of the space: the set's name, without a prefix */
    protected const string AGENT_SPACE_TABLE_NAME = 'AgentSpaces';

    protected ?string $tablePrefixBefore = null;

    protected function setUp(): void
    {
        if (!PackageTestEnvironment::isRunWithFixtureApplication()) {
            self::markTestSkipped(
                'Test of the fixture application: set ' . PackageTestEnvironment::FIXTURE_APPLICATION_VARIABLE
                . '=1 and run the suites `application,integration`'
            );
        }
        parent::setUp();
        $this->tablePrefixBefore = $_ENV['DATABASE_TABLE_PREFIX'] ?? null;
        $_ENV['DATABASE_TABLE_PREFIX'] = '';
    }

    protected function tearDown(): void
    {
        if (PackageTestEnvironment::isRunWithFixtureApplication()) {
            if ($this->tablePrefixBefore === null) {
                unset($_ENV['DATABASE_TABLE_PREFIX']);
            } else {
                $_ENV['DATABASE_TABLE_PREFIX'] = $this->tablePrefixBefore;
            }
        }
        parent::tearDown();
    }

    public function testThePackageClassNamesResolveToTheApplicationsClasses(): void
    {
        $packageEntityClass = \DDD\Domain\AI\Entities\AgentSpaces\AgentSpace::class;

        self::assertInstanceOf(
            AgentSpacesService::class,
            DDDService::instance()->getService(\DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::class)
        );
        self::assertInstanceOf(AgentSpacesService::class, $packageEntityClass::getService());
        self::assertInstanceOf(AgentSpace::class, $packageEntityClass::newInstance());
        self::assertInstanceOf(DBAgentSpace::class, $packageEntityClass::getRepoClassInstance());
        self::assertSame(AgentSpaces::class, AgentSpace::getEntitySetClass());
    }

    public function testTheSpaceHasOneTableDefinedByTheApplicationsEntity(): void
    {
        $entityClassesOfTheSpaceTable = [];
        $databaseModels = EntityModelGeneratorService::filterOutExcludedTables(
            EntityModelGeneratorService::getDatabaseModels(null, filterOverriddenEntities: true)
        );
        foreach ($databaseModels->getElements() as $databaseModel) {
            if ($databaseModel->sqlTableName === self::AGENT_SPACE_TABLE_NAME) {
                $entityClassesOfTheSpaceTable[] = $databaseModel->entityClassWithNamespace->getNameWithNamespace();
            }
        }

        self::assertSame([AgentSpace::class], $entityClassesOfTheSpaceTable);
        self::assertSame(self::AGENT_SPACE_TABLE_NAME, DBAgentSpaceModel::getTableName());
        self::assertSame(self::AGENT_SPACE_TABLE_NAME, \DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel::getTableName());
    }

    public function testTheTableCarriesThePackageColumnsAndTheApplicationsRequiredUniqueReference(): void
    {
        $generatedSql = $this->generatedSql(AgentSpace::class);

        self::assertMatchesRegularExpression('/^\s*`workshopId` INT UNSIGNED NOT NULL,?$/m', $generatedSql);
        self::assertMatchesRegularExpression('/^\s*`accountId` [^\n]* DEFAULT NULL,?$/m', $generatedSql);
        self::assertMatchesRegularExpression('/^\s*`timezone` VARCHAR\(64\) [^\n]*DEFAULT NULL,?$/mi', $generatedSql);
        self::assertMatchesRegularExpression('/CREATE UNIQUE INDEX [^\n]* ON `AgentSpaces` \(`workshopId`\)/', $generatedSql);
    }

    public function testDeletingTheApplicationsEntityOrTheAccountDeletesTheSpace(): void
    {
        $foreignKeyLinesByColumn = [];
        foreach (explode("\n", $this->generatedSql(AgentSpace::class)) as $line) {
            if (preg_match('/ADD CONSTRAINT .* FOREIGN KEY[^(]*\(`(\w+)`\)/', $line, $matches) === 1) {
                $foreignKeyLinesByColumn[$matches[1]] = trim($line);
            }
        }

        self::assertSame(['accountId', 'workshopId'], $this->getSortedColumnNames(array_keys($foreignKeyLinesByColumn)));
        self::assertStringContainsString('REFERENCES `Workshops`', $foreignKeyLinesByColumn['workshopId']);
        self::assertStringEndsWith('ON DELETE CASCADE;', $foreignKeyLinesByColumn['workshopId']);
        self::assertStringEndsWith('ON DELETE CASCADE;', $foreignKeyLinesByColumn['accountId']);
    }

    public function testTheCommittedModelsOfTheFixtureApplicationAreWhatTheGeneratorProduces(): void
    {
        $regeneratedEntityClasses = [];
        foreach ([AgentSpace::class, Workshop::class] as $entityClass) {
            $databaseModel = EntityModelGeneratorService::getDatabaseModels([$entityClass])->first();
            self::assertNotNull($databaseModel, $entityClass);
            $modelFile = $databaseModel->modelClassWithNamespace->filename;
            self::assertSame(
                dirname((string)(new ReflectionClass($entityClass::getRepoClassInstance()))->getFileName()),
                dirname($modelFile),
                'the model sits next to the repository of ' . $entityClass
            );
            self::assertFileExists($modelFile);
            self::assertSame($databaseModel->getDoctrineModelCode(), file_get_contents($modelFile), $entityClass);
            $regeneratedEntityClasses[] = $entityClass;
        }
        self::assertCount(2, $regeneratedEntityClasses);
    }

    protected function generatedSql(string $entityClass): string
    {
        return EntityModelGeneratorService::getDatabaseModels([$entityClass])->first()->getSql();
    }

    /**
     * @param string[] $columnNames
     * @return string[]
     */
    protected function getSortedColumnNames(array $columnNames): array
    {
        sort($columnNames);
        return $columnNames;
    }
}
