<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use Doctrine\DBAL\Connection;

/**
 * The tables of the package test kernel in the throwaway database: every database entity of the framework and of
 * the kernel's modules (`ddd-ai`, `ddd-argus`, `ddd-common-money`, this package), created from the entity classes
 * the way an application creates them — the statements `app:entity:show-sql` prints, for the engine the run uses.
 *
 * {@see self::createOnce()} drops and creates the tables once per process, so a run always works on the schema of
 * the checked-out source. {@see self::emptyAllTables()} removes every row before a test.
 */
class PackageTestDatabaseSchema
{
    /** @var bool Whether this process created the tables */
    protected static bool $isCreated = false;

    /** @var string[] The names of the tables of the schema */
    protected static array $tableNames = [];

    /** Drops and creates every table, once per process. */
    public static function createOnce(): void
    {
        if (static::$isCreated) {
            return;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $sqlDialect = EntityManagerFactory::getDialect();
        $databaseModels = static::findDatabaseModels();
        static::$tableNames = [];
        foreach ($databaseModels as $databaseModel) {
            static::$tableNames[] = $databaseModel->sqlTableName;
        }
        if ($sqlDialect->name() === SqlDialect::POSTGRES) {
            static::createTablesOnPostgres($connection, $sqlDialect, $databaseModels);
        } else {
            static::createTablesOnMariaDb($connection, $sqlDialect, $databaseModels);
        }
        static::$isCreated = true;
    }

    /** Removes every row of every table. A transaction a failed test left open is rolled back first. */
    public static function emptyAllTables(): void
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $sqlDialect = EntityManagerFactory::getDialect();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        if (static::$tableNames === []) {
            return;
        }
        $quotedTableNames = [];
        foreach (static::$tableNames as $tableName) {
            $quotedTableNames[] = $sqlDialect->quote($tableName);
        }
        if ($sqlDialect->name() === SqlDialect::POSTGRES) {
            $connection->executeStatement('TRUNCATE TABLE ' . implode(', ', $quotedTableNames) . ' RESTART IDENTITY CASCADE');
            return;
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($quotedTableNames as $quotedTableName) {
                $connection->executeStatement('TRUNCATE TABLE ' . $quotedTableName);
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @return DatabaseModel[] The model of every table of the kernel, one per table
     */
    protected static function findDatabaseModels(): array
    {
        $databaseModels = EntityModelGeneratorService::filterOutExcludedTables(
            EntityModelGeneratorService::getDatabaseModels(null, filterOverriddenEntities: true)
        );
        return $databaseModels->getElements();
    }

    /**
     * PostgreSQL: the extensions, the collation and the `ddd_*` functions the framework's schema needs, then the
     * tables in the order of the statement phases (tables before the foreign keys that point at them).
     *
     * @param DatabaseModel[] $databaseModels
     */
    protected static function createTablesOnPostgres(Connection $connection, SqlDialect $sqlDialect, array $databaseModels): void
    {
        foreach ($databaseModels as $databaseModel) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $sqlDialect->quote($databaseModel->sqlTableName) . ' CASCADE');
        }
        $ddlRenderer = $sqlDialect->ddlRenderer();
        $ddlStatements = $ddlRenderer->ensure();
        foreach ($databaseModels as $databaseModel) {
            $ddlStatements = $ddlStatements->withStatementsOf($ddlRenderer->renderCreate($databaseModel));
        }
        foreach ($ddlStatements->sortedByPhase()->getSqlList() as $sql) {
            $connection->executeStatement($sql);
        }
    }

    /**
     * MariaDB: the statements of each model as generated. A foreign key may point at a table a later model creates,
     * so the foreign key checks are off while the tables are created.
     *
     * @param DatabaseModel[] $databaseModels
     */
    protected static function createTablesOnMariaDb(Connection $connection, SqlDialect $sqlDialect, array $databaseModels): void
    {
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($databaseModels as $databaseModel) {
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $sqlDialect->quote($databaseModel->sqlTableName));
            }
            foreach ($databaseModels as $databaseModel) {
                foreach (static::splitIntoStatements($databaseModel->getSql()) as $sql) {
                    $connection->executeStatement($sql);
                }
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * The generated SQL of a model is one string of `;`-terminated statements and `#` comment lines.
     *
     * @return string[]
     */
    protected static function splitIntoStatements(string $generatedSql): array
    {
        $sqlWithoutCommentLines = (string)preg_replace('/^\s*#.*$/m', '', $generatedSql);
        $statements = [];
        foreach ((array)preg_split('/;\s*$/m', $sqlWithoutCommentLines) as $statement) {
            if (trim((string)$statement) !== '') {
                $statements[] = trim((string)$statement);
            }
        }
        return $statements;
    }
}
