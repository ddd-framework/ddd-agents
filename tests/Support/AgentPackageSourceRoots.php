<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The source directories a test reads source files from, in the package's own run: only the package's `src/`.
 * Same API as the application-side helper the moved tests used, so a moved test keeps its calls.
 */
class AgentPackageSourceRoots
{
    public static function getPackageSourceDirectory(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    /** @return string[] */
    public static function getSourceDirectories(): array
    {
        return [static::getPackageSourceDirectory()];
    }

    public static function resolveSourcePath(string $pathRelativeToSource): string
    {
        return static::getPackageSourceDirectory() . '/' . $pathRelativeToSource;
    }

    public static function findPathRelativeToSource(string $absolutePath): string
    {
        foreach (static::getSourceDirectories() as $sourceDirectory) {
            if (str_starts_with($absolutePath, $sourceDirectory . '/')) {
                return substr($absolutePath, strlen($sourceDirectory) + 1);
            }
        }
        return $absolutePath;
    }

    /** @return array<string, string> path relative to the source directory => absolute path, sorted by relative path */
    public static function findPhpFiles(string $relativeFolder = ''): array
    {
        $absolutePathsByRelativePath = [];
        foreach (static::getSourceDirectories() as $sourceDirectory) {
            $folder = rtrim($sourceDirectory . '/' . $relativeFolder, '/');
            if (!is_dir($folder)) {
                continue;
            }
            $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
            foreach ($fileIterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $absolutePathsByRelativePath[substr($file->getPathname(), strlen($sourceDirectory) + 1)] = $file->getPathname();
                }
            }
        }
        ksort($absolutePathsByRelativePath);
        return $absolutePathsByRelativePath;
    }
}
