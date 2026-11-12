<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The cache and log directories of the package kernel: below the system temp directory (never inside the repository),
 * keyed by the newest modification time of the kernel configuration and the package source. The kernel runs without
 * debug (no freshness check of the compiled container), so a changed service or config file must lead to a new
 * directory; the directories of older keys are removed. A run with the fixture application
 * ({@see PackageTestEnvironment::isRunWithFixtureApplication()}) compiles another container and keeps directories of
 * its own.
 */
class PackageKernelDirectories
{
    public function __construct(protected string $packageDirectory)
    {
    }

    /** Sets APP_CACHE_DIR / APP_LOG_DIR (unless the environment sets them) and removes directories of older keys. */
    public function apply(): void
    {
        $kernelVariant = PackageTestEnvironment::isRunWithFixtureApplication() ? '|fixture-application' : '';
        $directoryPrefix = sys_get_temp_dir() . '/ddd-agents-test-kernel-' . substr(md5($this->packageDirectory . $kernelVariant), 0, 12) . '-';
        $kernelDirectory = $directoryPrefix . $this->findNewestModificationTime();
        foreach (glob($directoryPrefix . '*', GLOB_ONLYDIR) ?: [] as $existingDirectory) {
            if ($existingDirectory !== $kernelDirectory) {
                $this->removeDirectory($existingDirectory);
            }
        }
        foreach (['APP_CACHE_DIR' => '/cache', 'APP_LOG_DIR' => '/log'] as $directoryEnvironmentVariable => $directorySuffix) {
            if (empty($_SERVER[$directoryEnvironmentVariable])) {
                $_SERVER[$directoryEnvironmentVariable] = $_ENV[$directoryEnvironmentVariable] = $kernelDirectory . $directorySuffix;
            }
        }
    }

    protected function findNewestModificationTime(): int
    {
        $newestModificationTime = 0;
        $watchedDirectories = [$this->packageDirectory . '/tests/Support/kernel', $this->packageDirectory . '/src', $this->packageDirectory . '/config'];
        if (PackageTestEnvironment::isRunWithFixtureApplication()) {
            $watchedDirectories[] = $this->packageDirectory . '/tests/Fixtures/Application';
        }
        foreach ($watchedDirectories as $watchedDirectory) {
            if (!is_dir($watchedDirectory)) {
                continue;
            }
            $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($watchedDirectory, FilesystemIterator::SKIP_DOTS));
            foreach ($fileIterator as $file) {
                $newestModificationTime = max($newestModificationTime, (int)$file->getMTime());
            }
        }
        return $newestModificationTime;
    }

    protected function removeDirectory(string $directory): void
    {
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($fileIterator as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($directory);
    }
}
