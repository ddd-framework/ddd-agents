<?php

declare(strict_types=1);

namespace AgentsReference\Infrastructure\Kernel;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The cache and log directories of the reference kernel: below the system temp directory (never inside the
 * repository), keyed by the newest modification time of the package source, the package config and this host. The
 * directories of older keys are removed. An explicit APP_CACHE_DIR / APP_LOG_DIR in the environment wins.
 */
class ReferenceKernelDirectories
{
    public function __construct(protected string $packageDirectory, protected string $referenceDirectory)
    {
    }

    public function apply(): void
    {
        $directoryPrefix = sys_get_temp_dir() . '/ddd-agents-reference-kernel-' . substr(md5($this->referenceDirectory), 0, 12) . '-';
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
        $watchedDirectories = [
            $this->packageDirectory . '/src',
            $this->packageDirectory . '/config',
            $this->referenceDirectory . '/src',
            $this->referenceDirectory . '/config',
        ];
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
