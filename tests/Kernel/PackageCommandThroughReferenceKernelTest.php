<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Tests\Agents\Support\PackageTestAutoloader;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Process\Process;

/**
 * A console command of the module is registered by the module alone: the reference host (`examples/reference-kernel`)
 * declares no command, yet its console lists the module's command and starts it. The host runs in a child process
 * (its own kernel and cache directory below the system temp directory), as in {@see ReferenceKernelSmokeTest}.
 */
class PackageCommandThroughReferenceKernelTest extends UnitTestCase
{
    /** @var string The command of the module the reference host must start */
    public const string MODULE_COMMAND_NAME = 'app:ai-conversation:mark-finalized';

    public function testTheReferenceHostListsTheModuleCommand(): void
    {
        $listProcess = $this->runReferenceConsole(['list', '--raw']);

        self::assertSame(0, $listProcess->getExitCode(), $listProcess->getOutput() . $listProcess->getErrorOutput());
        self::assertMatchesRegularExpression('~^' . preg_quote(self::MODULE_COMMAND_NAME, '~') . '\s~m', $listProcess->getOutput());
    }

    public function testTheReferenceHostStartsTheModuleCommand(): void
    {
        $helpProcess = $this->runReferenceConsole([self::MODULE_COMMAND_NAME, '--help']);

        self::assertSame(0, $helpProcess->getExitCode(), $helpProcess->getOutput() . $helpProcess->getErrorOutput());
        self::assertStringContainsString('--idle-minutes', $helpProcess->getOutput());
        self::assertStringContainsString('--dry-run', $helpProcess->getOutput());
    }

    /** @param string[] $consoleArguments */
    protected function runReferenceConsole(array $consoleArguments): Process
    {
        $packageDirectory = dirname(__DIR__, 2);
        $vendorDirectory = getenv(PackageTestAutoloader::VENDOR_DIRECTORY_ENV) ?: $packageDirectory . '/vendor';
        $consoleProcess = new Process(
            [PHP_BINARY, $packageDirectory . '/examples/reference-kernel/bin/console.php', ...$consoleArguments],
            $packageDirectory,
            [PackageTestAutoloader::VENDOR_DIRECTORY_ENV => $vendorDirectory, 'APP_ENV' => 'prod', 'APP_DEBUG' => '0', ...ReferenceKernelSmokeTest::FAKE_KEY_ENVIRONMENT],
            null,
            300
        );
        $consoleProcess->run();
        return $consoleProcess;
    }
}
