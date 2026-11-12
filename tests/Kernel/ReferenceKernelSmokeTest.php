<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Domain\Common\Repo\DB\Accounts\DBAccount;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Tests\Agents\Support\PackageTestAutoloader;
use DDD\Tests\Agents\Support\PackageTestEnvironment;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Process\Process;

/**
 * The reference host (`examples/reference-kernel`, namespace `AgentsReference\`) cannot rot silently: its kernel boots
 * the framework, the three modules and this package with its own config, its container compiles, and its router
 * serves the package's chat routes through its controller. The host runs in a child process (its own kernel, its own
 * cache directory below the system temp directory), never inside this test process's kernel.
 */
class ReferenceKernelSmokeTest extends UnitTestCase
{
    /** @var string[] Routes the reference host must serve (its own start route plus the package's polling read) */
    public const array REQUIRED_ROUTE_PATHS = [
        '/api/conversations/start',
        '/api/conversations/{aiConversationId}/runState',
        '/api/conversations/{aiConversationId}/messages',
        '/api/conversations/{aiConversationId}/postMessage',
    ];

    /**
     * The host's key-shaped variables for the child process: obviously fake, valid nowhere (no committed env file
     * carries a value; `examples/reference-kernel/.env.dist` lists them empty).
     *
     * @var string[]
     */
    public const array FAKE_KEY_ENVIRONMENT = [
        'APP_SECRET' => PackageTestEnvironment::NOT_A_SECRET,
        'DB_DEFAULT_CONNECTION_USER' => PackageTestEnvironment::NOT_A_SECRET,
        'DB_DEFAULT_CONNECTION_PASSWORD' => PackageTestEnvironment::NOT_A_SECRET,
        'OPENROUTER_KEY' => PackageTestEnvironment::NOT_A_SECRET,
        'JWT_HASH_KEY' => PackageTestEnvironment::NOT_A_SECRET,
    ];

    public function testTheReferenceKernelContainerCompiles(): void
    {
        $lintProcess = $this->runReferenceConsole(['lint:container']);
        self::assertSame(0, $lintProcess->getExitCode(), $lintProcess->getOutput() . $lintProcess->getErrorOutput());
        self::assertStringContainsString('The container was linted successfully', $lintProcess->getOutput());
    }

    public function testTheReferenceKernelServesTheChatRoutes(): void
    {
        $routerProcess = $this->runReferenceConsole(['debug:router']);
        self::assertSame(0, $routerProcess->getExitCode(), $routerProcess->getOutput() . $routerProcess->getErrorOutput());
        foreach (self::REQUIRED_ROUTE_PATHS as $requiredRoutePath) {
            self::assertStringContainsString($requiredRoutePath, $routerProcess->getOutput());
        }
    }

    /** A host that keeps the framework's Account reads it through DBAccount, whose ORM model must be the model class. */
    public function testTheFrameworkAccountRepositoryNamesItsModel(): void
    {
        self::assertSame(DBAccountModel::class, DBAccount::BASE_ORM_MODEL);
    }

    /** @param string[] $consoleArguments */
    protected function runReferenceConsole(array $consoleArguments): Process
    {
        $packageDirectory = dirname(__DIR__, 2);
        $vendorDirectory = getenv(PackageTestAutoloader::VENDOR_DIRECTORY_ENV) ?: $packageDirectory . '/vendor';
        $consoleProcess = new Process(
            [PHP_BINARY, $packageDirectory . '/examples/reference-kernel/bin/console.php', ...$consoleArguments],
            $packageDirectory,
            [PackageTestAutoloader::VENDOR_DIRECTORY_ENV => $vendorDirectory, 'APP_ENV' => 'prod', 'APP_DEBUG' => '0', ...self::FAKE_KEY_ENVIRONMENT],
            null,
            300
        );
        $consoleProcess->run();
        return $consoleProcess;
    }
}
