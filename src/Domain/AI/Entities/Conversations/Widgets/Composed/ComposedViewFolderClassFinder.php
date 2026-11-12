<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Libs\Config;
use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * The folder convention behind the kit's registries ("a lane adds files, never a line in a list"):
 * turns every `*<Suffix>.php` below a set of folders into its class name by PSR-4 over the source roots (the application
 * and every installed module, {@see AgentSourceRootsService}), keeps the concrete
 * subclasses of a base, and reports build errors the way `ConversationWidgetClasses` does — logged and skipped in
 * production, thrown under tests. Used by the entity-type and data-source registries. Reads the file system only.
 */
class ComposedViewFolderClassFinder
{
    /** The `src/` directory of this package. */
    public static function getSourceDirectory(): string
    {
        return dirname(__DIR__, 6);
    }

    /**
     * The source roots the folder conventions read: the application's `src/` (`App\`) first, then every installed
     * DDD module's `src/` (`DDD\`) — {@see AgentSourceRootsService}.
     */
    public static function getSourceRoots(): AgentSourceRoots
    {
        /** @var AgentSourceRootsService $agentSourceRootsService */
        $agentSourceRootsService = DDDService::instance()->getService(AgentSourceRootsService::class);
        return $agentSourceRootsService->getSourceRoots();
    }

    /** The class name of a PHP file below one of the source roots, by PSR-4; null outside them. */
    public static function findClassNameForFilePath(string $classFilePath): ?string
    {
        return static::getSourceRoots()->findClassNameForFilePath($classFilePath);
    }

    /**
     * Every `Domain/<Domain>/<relativeFolder>` that exists below any source root, sorted.
     *
     * @return string[]
     */
    public static function findDomainFolders(string $relativeFolder): array
    {
        return static::getSourceRoots()->findDomainFolders($relativeFolder);
    }

    /**
     * The class names of every `*<classNameSuffix>.php` below `$folders` (any depth), sorted.
     *
     * @param string[] $folders
     * @return string[]
     */
    public static function findClassNamesBelowFolders(string $classNameSuffix, array $folders): array
    {
        $classNames = [];
        foreach (array_unique($folders) as $folder) {
            if (!is_dir($folder)) {
                continue;
            }
            $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
            foreach ($fileIterator as $classFile) {
                $classFilePath = (string)$classFile;
                $className = str_ends_with($classFilePath, $classNameSuffix . '.php') ? static::findClassNameForFilePath($classFilePath) : null;
                if ($className === null) {
                    continue;
                }
                $classNames[] = $className;
            }
        }
        $classNames = array_values(array_unique($classNames));
        sort($classNames);
        return $classNames;
    }

    /**
     * The concrete subclasses of `$baseClass` among `$classNames`; a class that fails to load is a build error.
     *
     * @template T of object
     * @param class-string<T> $baseClass
     * @return array<int, class-string<T>>
     */
    public static function filterConcreteSubclasses(string $baseClass, string ...$classNames): array
    {
        $concreteSubclasses = [];
        foreach ($classNames as $className) {
            try {
                if (!class_exists($className)) {
                    static::handleBuildError(sprintf('The class %s does not load: no such class.', $className));
                    continue;
                }
            } catch (Throwable $loadError) {
                static::handleBuildError(sprintf('The class %s does not load: %s', $className, $loadError->getMessage()));
                continue;
            }
            if (is_subclass_of($className, $baseClass) && !(new ReflectionClass($className))->isAbstract()) {
                $concreteSubclasses[] = $className;
            }
        }
        return $concreteSubclasses;
    }

    /**
     * Override resolution before duplicate validation: of two discovered classes that register under the same key
     * (a widget type, a node type, a data-source name, an entity type), the one that EXTENDS the other wins — an
     * application or module class that subclasses a core class replaces it. Null = the two are unrelated: a real
     * duplicate the caller reports as a build error.
     *
     * @param class-string $registeredClassName the class registered first
     * @param class-string $candidateClassName the class found later
     * @return class-string|null the class to keep, or null for an unrelated duplicate
     */
    public static function resolveOverridingClassName(string $registeredClassName, string $candidateClassName): ?string
    {
        if (is_subclass_of($candidateClassName, $registeredClassName)) {
            return $candidateClassName;
        }
        if (is_subclass_of($registeredClassName, $candidateClassName)) {
            return $registeredClassName;
        }
        return null;
    }

    /** @throws LogicException only under tests (`APP_ENV=test`) */
    public static function handleBuildError(string $buildErrorMessage): void
    {
        if (Config::getEnv('APP_ENV') === 'test') {
            throw new LogicException($buildErrorMessage);
        }
        DDDService::instance()->getLogger()->error($buildErrorMessage . ' The class is skipped.');
    }
}
