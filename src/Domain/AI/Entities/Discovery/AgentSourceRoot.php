<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Discovery;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One PHP source root the agent core's folder conventions read (widgets, signal sources, composed-view nodes, data
 * sources, entity-reference resolvers): a `src/` directory and the PSR-4 root namespace it maps to — the application's
 * (`App\`) or an installed DDD module's (`DDD\`, shared by every module).
 */
class AgentSourceRoot extends ValueObject
{
    /** The PSR-4 root namespace with its trailing backslash (`App\`, `DDD\`). */
    public string $rootNamespace = '';

    /** The real path of the `src/` directory, without a trailing slash. */
    public string $directory = '';

    /** Whether this is the application's root (its classes override module classes). */
    public bool $isApplication = false;

    /** A root over `$directory` (resolved to its real path; null when it is no directory). */
    public static function forDirectory(string $rootNamespace, string $directory, bool $isApplication): ?static
    {
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !is_dir($realDirectory)) {
            return null;
        }
        $agentSourceRoot = static::newInstance();
        $agentSourceRoot->rootNamespace = rtrim($rootNamespace, '\\') . '\\';
        $agentSourceRoot->directory = rtrim($realDirectory, '/');
        $agentSourceRoot->isApplication = $isApplication;
        return $agentSourceRoot;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->directory);
    }

    /** Whether `$filePath` lies below this root. */
    public function containsFilePath(string $filePath): bool
    {
        return str_starts_with($filePath, $this->directory . '/');
    }

    /** The class name of a PHP file below this root, by PSR-4; null outside it or for a non-PHP file. */
    public function findClassNameForFilePath(string $classFilePath): ?string
    {
        if (!$this->containsFilePath($classFilePath) || !str_ends_with($classFilePath, '.php')) {
            return null;
        }
        return $this->rootNamespace . str_replace('/', '\\', substr($classFilePath, strlen($this->directory) + 1, -strlen('.php')));
    }
}
