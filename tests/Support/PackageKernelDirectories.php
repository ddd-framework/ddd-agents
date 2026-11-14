<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The cache and log directories of the package kernel: below the system temp directory (never inside the repository),
 * keyed by a fingerprint of the kernel configuration and the package source — the path, modification time and size of
 * every file. The kernel runs without debug (no freshness check of the compiled container), so an added, removed or
 * changed service or config file must lead to a new directory, also when it carries an older modification time than
 * the newest file (a file copied with its time kept); the directories of older keys are removed. A run with the fixture application
 * ({@see PackageTestEnvironment::isRunWithFixtureApplication()}) compiles another container and keeps directories of
 * its own.
 */
class PackageKernelDirectories
{
    public function __construct(protected string $packageDirectory)
    {
    }

    /**
     * Sets APP_CACHE_DIR / APP_LOG_DIR (unless the environment sets them) and removes directories of older keys. A
     * process that inherits APP_CACHE_DIR (a console the test suite starts) removes nothing: the directory in use is
     * its parent's, whatever key the files have by now.
     */
    public function apply(): void
    {
        $kernelVariant = PackageTestEnvironment::isRunWithFixtureApplication() ? '|fixture-application' : '';
        $directoryPrefix = sys_get_temp_dir() . '/ddd-agents-test-kernel-' . substr(md5($this->packageDirectory . $kernelVariant), 0, 12) . '-';
        $kernelDirectory = $directoryPrefix . $this->buildSourceFingerprint();
        $cacheDirectoryIsInherited = !empty($_SERVER['APP_CACHE_DIR']);
        foreach ($cacheDirectoryIsInherited ? [] : (glob($directoryPrefix . '*', GLOB_ONLYDIR) ?: []) as $existingDirectory) {
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

    /**
     * @return string a short hash of the path, modification time and size of every watched file: it changes when a
     *     file is added, removed or changed, whatever its modification time
     */
    protected function buildSourceFingerprint(): string
    {
        $fileDescriptions = [];
        foreach ($this->getWatchedDirectories() as $watchedDirectory) {
            if (!is_dir($watchedDirectory)) {
                continue;
            }
            $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($watchedDirectory, FilesystemIterator::SKIP_DOTS));
            foreach ($fileIterator as $file) {
                $fileDescriptions[] = $file->getPathname() . ':' . (int)$file->getMTime() . ':' . (int)$file->getSize();
            }
        }
        sort($fileDescriptions);
        return substr(md5(implode("\n", $fileDescriptions)), 0, 16);
    }

    /** @return string[] the directories whose files decide the compiled container */
    protected function getWatchedDirectories(): array
    {
        $watchedDirectories = [$this->packageDirectory . '/tests/Support/kernel', $this->packageDirectory . '/src', $this->packageDirectory . '/config'];
        if (PackageTestEnvironment::isRunWithFixtureApplication()) {
            $watchedDirectories[] = $this->packageDirectory . '/tests/Fixtures/Application';
        }
        return $watchedDirectories;
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
