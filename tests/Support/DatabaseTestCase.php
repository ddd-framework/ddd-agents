<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use App\Domain\Workshops\Repo\DB\Workshops\DBWorkshopModel;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use Doctrine\DBAL\Connection;

/**
 * Base class for tests that read or write the database. They run against the throwaway database of
 * `tests/Integration/docker-compose.yml` on the engine {@see PackageTestEnvironment::DATABASE_TEST_DRIVER_VARIABLE}
 * names (`pdo_mysql` or `pdo_pgsql`) and skip themselves without it. Every such test is meant to pass on BOTH
 * engines: the package supports MariaDB and PostgreSQL.
 *
 * The tables are created once per process from the entity classes ({@see PackageTestDatabaseSchema}) and emptied
 * before every test. A test runs without an enclosing transaction: the code under test opens, commits and rolls
 * back its own (run claims, locks, a write that must be visible to a second connection), and on PostgreSQL a failed
 * statement would abort an enclosing transaction for the rest of the test.
 *
 * A test class whose code is not ready for PostgreSQL yet names the reason in
 * {@see self::getReasonNotSupportedOnPostgres()} and is skipped there — the open list of the conversion is the list
 * of classes that override it.
 *
 * The same tests run a second time together with the fixture application
 * ({@see PackageTestEnvironment::isRunWithFixtureApplication()}), where the space is the application's subclass with a
 * column of its own: a test stores a space row through {@see self::insertAgentSpaceRow()}, which gives the row what
 * the application requires.
 */
abstract class DatabaseTestCase extends IntegrationTestCase
{
    /** @var int In a run with the fixture application: the id of the workshop of an inserted space is this plus the id of the space */
    public const int FIXTURE_WORKSHOP_ID_OFFSET = 9000;

    protected function setUp(): void
    {
        parent::setUp();
        $databaseTestDriver = PackageTestEnvironment::getDatabaseTestDriver();
        if ($databaseTestDriver === null) {
            self::markTestSkipped(
                'Database test: start tests/Integration/docker-compose.yml and set '
                . PackageTestEnvironment::DATABASE_TEST_DRIVER_VARIABLE . '=pdo_mysql|pdo_pgsql'
            );
        }
        $reasonNotSupportedOnPostgres = $this->getReasonNotSupportedOnPostgres();
        if ($databaseTestDriver === PackageTestEnvironment::DRIVER_POSTGRES && $reasonNotSupportedOnPostgres !== null) {
            self::markTestSkipped('Not on PostgreSQL yet: ' . $reasonNotSupportedOnPostgres);
        }
        PackageTestDatabaseSchema::createOnce();
        // ids repeat from test to test: an entity an earlier test loaded must not be served for another row
        DoctrineEntityRegistry::clear();
        EntityManagerFactory::clearAllInstanceCaches();
        PackageTestDatabaseSchema::emptyAllTables();
    }

    /**
     * Why the tests of this class cannot pass on PostgreSQL yet, or null when they must.
     */
    protected function getReasonNotSupportedOnPostgres(): ?string
    {
        return null;
    }

    protected function getDatabaseConnection(): Connection
    {
        return EntityManagerFactory::getInstance()->getConnection();
    }

    protected function getSqlDialect(): SqlDialect
    {
        return EntityManagerFactory::getDialect();
    }

    protected function isPostgres(): bool
    {
        return $this->getSqlDialect()->name() === SqlDialect::POSTGRES;
    }

    /**
     * Inserts one row with quoted table and column names, so the same call works on both engines (DBAL's own
     * `insert()` leaves mixed-case names unquoted, which PostgreSQL folds to lower case).
     *
     * @param array<string, mixed> $columnValues column name => value
     */
    protected function insertRow(string $tableName, array $columnValues): void
    {
        $sqlDialect = $this->getSqlDialect();
        $quotedColumnNames = [];
        foreach (array_keys($columnValues) as $columnName) {
            $quotedColumnNames[] = $sqlDialect->quote($columnName);
        }
        $this->getDatabaseConnection()->executeStatement(
            'INSERT INTO ' . $sqlDialect->quote($tableName) . ' (' . implode(', ', $quotedColumnNames) . ') VALUES ('
            . implode(', ', array_fill(0, count($columnValues), '?')) . ')',
            array_values($columnValues)
        );
    }

    /**
     * Inserts one space row. In a run with the fixture application every space stands for a workshop: the workshop
     * row is inserted first — owned by the account the space row names, so the application's read rights give what
     * the package's give for the same row — and the space row references it.
     *
     * @param array<string, mixed> $columnValues column name => value of the package's columns; `id` is required
     */
    protected function insertAgentSpaceRow(array $columnValues): void
    {
        if (PackageTestEnvironment::isRunWithFixtureApplication()) {
            $workshopId = self::FIXTURE_WORKSHOP_ID_OFFSET + (int)$columnValues['id'];
            $this->insertRow(DBWorkshopModel::getTableName(), [
                'id' => $workshopId,
                'name' => 'Workshop of space ' . $columnValues['id'],
                'accountId' => $columnValues['accountId'] ?? null,
            ]);
            $columnValues['workshopId'] = $workshopId;
        }
        $this->insertRow(DBAgentSpaceModel::getTableName(), $columnValues);
    }

    /**
     * @return array<string, mixed>|null The stored row of `$tableName` with the id `$id` as the database returns it
     */
    protected function findStoredRow(string $tableName, int $id): ?array
    {
        $sqlDialect = $this->getSqlDialect();
        $storedRow = $this->getDatabaseConnection()->fetchAssociative(
            'SELECT * FROM ' . $sqlDialect->quote($tableName) . ' WHERE ' . $sqlDialect->quote('id') . ' = ?',
            [$id]
        );
        return $storedRow === false ? null : $storedRow;
    }
}
