<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Tests\Agents\Support\PackageKernelDirectories;
use DDD\Tests\Agents\Support\PackageTestEnvironment;
use DDD\Tests\Agents\Support\UnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;

/**
 * The compiled container of the package kernel is keyed by every source file: a file added with an older modification
 * time than the newest file (copied with its time kept) or a removed file leads to another key, so the console and
 * the test run never boot a container compiled before the change. Only the process that chooses its directory removes
 * the directories of older keys; a process that inherits the directory removes none.
 */
class PackageKernelDirectoriesTest extends UnitTestCase
{
    protected string $packageDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->packageDirectory = sys_get_temp_dir() . '/ddd-agents-kernel-directories-test-' . getmypid() . '-' . uniqid();
        mkdir($this->packageDirectory . '/src/Commands', 0777, true);
        mkdir($this->packageDirectory . '/config', 0777, true);
        file_put_contents($this->packageDirectory . '/src/Commands/FirstCommand.php', '<?php');
        touch($this->packageDirectory . '/src/Commands/FirstCommand.php', 1_800_000_000);
    }

    protected function tearDown(): void
    {
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->packageDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($fileIterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->packageDirectory);
        parent::tearDown();
    }

    public function testAFileAddedWithAnOlderModificationTimeChangesTheKey(): void
    {
        $keyBefore = $this->buildSourceFingerprint();
        file_put_contents($this->packageDirectory . '/src/Commands/SecondCommand.php', '<?php');
        touch($this->packageDirectory . '/src/Commands/SecondCommand.php', 1_700_000_000);

        self::assertNotSame($keyBefore, $this->buildSourceFingerprint());
    }

    public function testARemovedFileChangesTheKey(): void
    {
        file_put_contents($this->packageDirectory . '/config/agentSettings.php', '<?php');
        $keyBefore = $this->buildSourceFingerprint();
        unlink($this->packageDirectory . '/config/agentSettings.php');

        self::assertNotSame($keyBefore, $this->buildSourceFingerprint());
    }

    public function testUnchangedFilesKeepTheKey(): void
    {
        self::assertSame($this->buildSourceFingerprint(), $this->buildSourceFingerprint());
    }

    public function testAProcessThatInheritsItsCacheDirectoryRemovesNoDirectoryOfAnotherKey(): void
    {
        $kernelVariant = PackageTestEnvironment::isRunWithFixtureApplication() ? '|fixture-application' : '';
        $directoryOfAnotherKey = sys_get_temp_dir() . '/ddd-agents-test-kernel-' . substr(md5($this->packageDirectory . $kernelVariant), 0, 12) . '-anotherkey';
        mkdir($directoryOfAnotherKey);
        $directoryVariablesBefore = [];
        foreach (['APP_CACHE_DIR', 'APP_LOG_DIR'] as $directoryEnvironmentVariable) {
            $directoryVariablesBefore[$directoryEnvironmentVariable] = [$_SERVER[$directoryEnvironmentVariable] ?? null, $_ENV[$directoryEnvironmentVariable] ?? null];
        }
        try {
            $_SERVER['APP_CACHE_DIR'] = $this->packageDirectory . '/inherited-cache';
            (new PackageKernelDirectories($this->packageDirectory))->apply();
            self::assertDirectoryExists($directoryOfAnotherKey, 'the directory may be the one the starting process runs on');

            unset($_SERVER['APP_CACHE_DIR']);
            (new PackageKernelDirectories($this->packageDirectory))->apply();
            self::assertDirectoryDoesNotExist($directoryOfAnotherKey, 'the process that chooses its own directory removes the older keys');
        } finally {
            foreach ($directoryVariablesBefore as $directoryEnvironmentVariable => [$serverValueBefore, $environmentValueBefore]) {
                unset($_SERVER[$directoryEnvironmentVariable], $_ENV[$directoryEnvironmentVariable]);
                if ($serverValueBefore !== null) {
                    $_SERVER[$directoryEnvironmentVariable] = $serverValueBefore;
                }
                if ($environmentValueBefore !== null) {
                    $_ENV[$directoryEnvironmentVariable] = $environmentValueBefore;
                }
            }
            is_dir($directoryOfAnotherKey) && rmdir($directoryOfAnotherKey);
        }
    }

    protected function buildSourceFingerprint(): string
    {
        $buildSourceFingerprint = new ReflectionMethod(PackageKernelDirectories::class, 'buildSourceFingerprint');
        return (string)$buildSourceFingerprint->invoke(new PackageKernelDirectories($this->packageDirectory));
    }
}
