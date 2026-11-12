<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use Symfony\Component\Dotenv\Dotenv;

/**
 * The environment of the package test kernel, set where the kernel is built (`tests/bootstrap.php`,
 * `tests/Support/bin/console.php`) — no committed env file carries it.
 *
 * Order: the process environment wins, then a git-ignored `tests/Support/kernel/.env.test` (a developer's own values),
 * then — when {@see self::DATABASE_TEST_DRIVER_VARIABLE} names a driver — the connection of the throwaway database of
 * `tests/Integration/docker-compose.yml` for that driver, then {@see self::DEFAULT_VALUES}.
 * `tests/Support/kernel/.env.dist` lists the same names with every key-shaped value empty.
 *
 * Without {@see self::DATABASE_TEST_DRIVER_VARIABLE} the run has no database: the default connection values point at
 * nothing, no test of the `package` suite opens a connection, and every database test skips itself
 * ({@see DatabaseTestCase}).
 */
class PackageTestEnvironment
{
    /** The value of every key-shaped variable in the package run: obviously fake, valid nowhere. */
    public const string NOT_A_SECRET = 'test-not-a-secret';

    /** Process variable that turns the database tests on and selects their engine: `pdo_mysql` or `pdo_pgsql` */
    public const string DATABASE_TEST_DRIVER_VARIABLE = 'DDD_AGENTS_TEST_DRIVER';

    /**
     * Process variable: `1` runs the package together with the fixture application of `tests/Fixtures/Application`
     * (an application that subclasses the space). Such a run is a run of its own: the framework resolves a package
     * class to the application's class of the same path for the whole process.
     */
    public const string FIXTURE_APPLICATION_VARIABLE = 'DDD_AGENTS_TEST_WITH_APPLICATION';

    /** Process variable: the host port of the throwaway MariaDB (default {@see self::DEFAULT_MARIADB_PORT}) */
    public const string MARIADB_PORT_VARIABLE = 'DDD_AGENTS_TEST_DB_PORT';

    /** Process variable: the host port of the throwaway PostgreSQL (default {@see self::DEFAULT_POSTGRES_PORT}) */
    public const string POSTGRES_PORT_VARIABLE = 'DDD_AGENTS_TEST_PG_PORT';

    public const string DRIVER_MARIADB = 'pdo_mysql';

    public const string DRIVER_POSTGRES = 'pdo_pgsql';

    public const string DEFAULT_MARIADB_PORT = '3318';

    public const string DEFAULT_POSTGRES_PORT = '5434';

    /** Name and password of the throwaway databases, as `tests/Integration/docker-compose.yml` creates them */
    public const string THROWAWAY_DATABASE_NAME = 'ddd_agents_test';

    public const string THROWAWAY_DATABASE_PASSWORD = 'ddd-agents-test';

    /** @var string[] Throwaway values only: no host, no credential of any real system */
    public const array DEFAULT_VALUES = [
        'APP_ENV' => 'test',
        'APP_DEBUG' => '0',
        'APP_SECRET' => self::NOT_A_SECRET,
        'DB_DEFAULT_CONNECTION_DRIVER' => 'pdo_mysql',
        'DB_DEFAULT_CONNECTION_HOST' => '127.0.0.1',
        'DB_DEFAULT_CONNECTION_HOST_PORT' => '3307',
        'DB_DEFAULT_CONNECTION_DB_NAME' => 'ddd_agents_test',
        'DB_DEFAULT_CONNECTION_USER' => self::NOT_A_SECRET,
        'DB_DEFAULT_CONNECTION_PASSWORD' => self::NOT_A_SECRET,
        'DB_DEFAULT_CONNECTION_DB_SERVER_VERSION' => 'mariadb-11.8.5',
        'DB_DEFAULT_CONNECTION_DB_SERVER_CHARSET' => 'utf8mb4',
        'DB_DEFAULT_DOCTRINE_PROXY_GENERATION_MODE' => 'AUTOGENERATE_EVAL',
        // read on PostgreSQL only: its named locks need a direct (not pooled) connection
        'DB_DEFAULT_DIRECT_HOST' => '127.0.0.1',
        'DB_DEFAULT_DIRECT_PORT' => self::DEFAULT_POSTGRES_PORT,
        // the package's tables carry no prefix
        'DATABASE_TABLE_PREFIX' => '',
        'LAZYLOAD_DEFAULT_REPO_TYPE' => 'DB',
    ];

    public function __construct(protected string $packageDirectory)
    {
    }

    /**
     * Loads the optional `.env.test`, then sets every value the process or that file left unset ($_ENV and $_SERVER):
     * the throwaway database of the selected driver first, the defaults after it.
     */
    public function apply(): void
    {
        $localEnvironmentFile = $this->packageDirectory . '/tests/Support/kernel/.env.test';
        if (is_file($localEnvironmentFile)) {
            (new Dotenv())->usePutenv(false)->load($localEnvironmentFile);
        }
        foreach (array_merge(self::DEFAULT_VALUES, self::getThrowawayDatabaseValues()) as $variableName => $fallbackValue) {
            $processValue = getenv($variableName);
            $value = $_ENV[$variableName] ?? ($processValue !== false ? $processValue : $fallbackValue);
            if ($value === null) {
                // a variable the selected engine must not see at all
                unset($_SERVER[$variableName], $_ENV[$variableName]);
                continue;
            }
            $_SERVER[$variableName] = $_ENV[$variableName] = $value;
        }
    }

    /**
     * The driver the database tests of this run use, or null when the run has no database.
     *
     * @return string|null {@see self::DRIVER_MARIADB}, {@see self::DRIVER_POSTGRES} or null
     */
    public static function getDatabaseTestDriver(): ?string
    {
        $databaseTestDriver = self::getProcessValue(self::DATABASE_TEST_DRIVER_VARIABLE);
        return in_array($databaseTestDriver, [self::DRIVER_MARIADB, self::DRIVER_POSTGRES], true) ? $databaseTestDriver : null;
    }

    /**
     * @return bool Whether this run loads the fixture application ({@see self::FIXTURE_APPLICATION_VARIABLE})
     */
    public static function isRunWithFixtureApplication(): bool
    {
        return self::getProcessValue(self::FIXTURE_APPLICATION_VARIABLE) === '1';
    }

    /**
     * The connection of the throwaway database of `tests/Integration/docker-compose.yml` for the selected driver;
     * empty when the run has no database.
     *
     * @return array<string, string|null> variable name => value; null = the variable stays unset for this engine
     */
    public static function getThrowawayDatabaseValues(): array
    {
        $databaseTestDriver = self::getDatabaseTestDriver();
        if ($databaseTestDriver === null) {
            return [];
        }
        $sharedValues = [
            'DB_DEFAULT_CONNECTION_DRIVER' => $databaseTestDriver,
            'DB_DEFAULT_CONNECTION_HOST' => '127.0.0.1',
            'DB_DEFAULT_CONNECTION_DB_NAME' => self::THROWAWAY_DATABASE_NAME,
            'DB_DEFAULT_CONNECTION_PASSWORD' => self::THROWAWAY_DATABASE_PASSWORD,
        ];
        if ($databaseTestDriver === self::DRIVER_POSTGRES) {
            $postgresPort = self::getProcessValue(self::POSTGRES_PORT_VARIABLE) ?? self::DEFAULT_POSTGRES_PORT;
            return $sharedValues + [
                'DB_DEFAULT_CONNECTION_HOST_PORT' => $postgresPort,
                'DB_DEFAULT_CONNECTION_USER' => 'postgres',
                'DB_DEFAULT_CONNECTION_DB_SERVER_VERSION' => '18.0',
                // a MariaDB-only setting: the framework refuses a PostgreSQL connection that carries it
                'DB_DEFAULT_CONNECTION_DB_SERVER_CHARSET' => null,
                // the direct connection of the named locks: the same single server
                'DB_DEFAULT_DIRECT_PORT' => $postgresPort,
            ];
        }
        return $sharedValues + [
            'DB_DEFAULT_CONNECTION_HOST_PORT' => self::getProcessValue(self::MARIADB_PORT_VARIABLE) ?? self::DEFAULT_MARIADB_PORT,
            'DB_DEFAULT_CONNECTION_USER' => 'root',
            'DB_DEFAULT_CONNECTION_DB_SERVER_VERSION' => 'mariadb-11.8.5',
            'DB_DEFAULT_CONNECTION_DB_SERVER_CHARSET' => 'utf8mb4',
        ];
    }

    /** The value of a process variable ($_ENV, $_SERVER or the process environment), null when it is not set or empty. */
    protected static function getProcessValue(string $variableName): ?string
    {
        $processValue = $_ENV[$variableName] ?? $_SERVER[$variableName] ?? getenv($variableName);
        return is_string($processValue) && $processValue !== '' ? $processValue : null;
    }
}
