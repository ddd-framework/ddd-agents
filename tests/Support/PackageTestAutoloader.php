<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use Composer\Autoload\ClassLoader;
use RuntimeException;

/**
 * The autoloader of the package's standalone test run (no application on the class path).
 *
 * It reads the generated Composer maps of a vendor directory (`vendor/composer/autoload_{classmap,psr4,namespaces,files}.php`)
 * and builds a fresh {@see ClassLoader} from them with three rules:
 * - every class map entry, PSR-4 / PSR-0 directory and autoload file outside the vendor directory (a host application's
 *   own `src/` and `Tests/`) is dropped, and so is every `App\` prefix and class — a host application's class can
 *   never resolve, so a test that needs one fails with "class not found" instead of silently testing the application;
 * - the one exception is the package's own fixture application (`tests/Fixtures/Application/src`, root namespace
 *   `App\`), and only in a run that asks for it ({@see PackageTestEnvironment::isRunWithFixtureApplication()});
 * - the package's own classes resolve from THIS repository (`src/` first for `DDD\`, `tests/` for `DDD\Tests\Agents\`),
 *   never from a vendor copy of the package;
 * - everything else (the `mgamadeus/*` framework packages, Symfony, Doctrine, PHPUnit) resolves from the vendor directory.
 *
 * The vendor directory is the package's own `vendor/` after `composer install`, or the one named by the environment
 * variable {@see self::VENDOR_DIRECTORY_ENV} (a host application's vendor, when the package is developed inside one).
 */
class PackageTestAutoloader
{
    /** @var string Environment variable naming the vendor directory whose Composer maps are read */
    public const string VENDOR_DIRECTORY_ENV = 'DDD_AGENTS_VENDOR_DIR';

    /** @var string The root namespace of a consuming application, never resolvable in the standalone run */
    public const string APPLICATION_ROOT_NAMESPACE = 'App\\';

    public function __construct(protected string $packageDirectory, protected string $vendorDirectory)
    {
    }

    /**
     * @param string $packageDirectory The package repository root
     * @return static The autoloader over the configured vendor directory
     */
    public static function forPackageDirectory(string $packageDirectory): static
    {
        $vendorDirectory = getenv(self::VENDOR_DIRECTORY_ENV) ?: $packageDirectory . '/vendor';
        $resolvedVendorDirectory = realpath($vendorDirectory);
        if ($resolvedVendorDirectory === false || !is_file($resolvedVendorDirectory . '/composer/autoload_classmap.php')) {
            throw new RuntimeException(sprintf(
                'No Composer vendor directory at %s: run composer install in the package, or set %s to a vendor directory',
                $vendorDirectory,
                self::VENDOR_DIRECTORY_ENV
            ));
        }
        return new static(realpath($packageDirectory) ?: $packageDirectory, $resolvedVendorDirectory);
    }

    /**
     * Registers the filtered loader and requires the vendor's autoload files (outside the host project).
     *
     * @param bool $unregistersOtherLoaders true (test run): every Composer class loader already registered (a host
     * application's) is unregistered first; false (PHPStan bootstrap): the analyser's own loader stays registered
     */
    public function register(bool $unregistersOtherLoaders = true): ClassLoader
    {
        require_once __DIR__ . '/PackageTestEnvironment.php';
        if (!class_exists(ClassLoader::class, false)) {
            require_once $this->vendorDirectory . '/composer/ClassLoader.php';
        }
        if ($unregistersOtherLoaders) {
            foreach (ClassLoader::getRegisteredLoaders() as $registeredLoader) {
                $registeredLoader->unregister();
            }
        }
        $classLoader = new ClassLoader($this->vendorDirectory);
        $classLoader->addPsr4('DDD\\Tests\\Agents\\', [$this->packageDirectory . '/tests']);
        $classLoader->addPsr4('DDD\\', [$this->packageDirectory . '/src']);
        if (PackageTestEnvironment::isRunWithFixtureApplication()) {
            $classLoader->addPsr4(self::APPLICATION_ROOT_NAMESPACE, [$this->getFixtureApplicationSourceDirectory()]);
        }
        $classLoader->addClassMap($this->buildClassMap());
        foreach ($this->buildPsr4Prefixes() as $prefix => $directories) {
            $classLoader->addPsr4($prefix, $directories);
        }
        foreach ($this->buildPsr0Prefixes() as $prefix => $directories) {
            $classLoader->add($prefix, $directories);
        }
        $classLoader->register(true);
        foreach ($this->buildAutoloadFiles() as $autoloadFile) {
            require_once $autoloadFile;
        }
        return $classLoader;
    }

    /** @return string The vendor directory whose maps are read */
    public function getVendorDirectory(): string
    {
        return $this->vendorDirectory;
    }

    /** @return string The vendor copy of this package inside the vendor directory (never loaded) */
    public function getVendorCopyOfPackageDirectory(): string
    {
        return $this->vendorDirectory . '/mgamadeus/ddd-agents';
    }

    /** @return string The source directory of the package's fixture application (root namespace `App\`) */
    public function getFixtureApplicationSourceDirectory(): string
    {
        return $this->packageDirectory . '/tests/Fixtures/Application/src';
    }

    /** @return array<string, string> class name => file, without application classes and without the package's vendor copy */
    protected function buildClassMap(): array
    {
        $keptClassMap = [];
        foreach ($this->readComposerMap('autoload_classmap.php') as $className => $classFile) {
            if (str_starts_with((string)$className, self::APPLICATION_ROOT_NAMESPACE) || !$this->isLoadablePath($classFile)) {
                continue;
            }
            $keptClassMap[$className] = $classFile;
        }
        return $keptClassMap;
    }

    /** @return array<string, string[]> */
    protected function buildPsr4Prefixes(): array
    {
        return $this->filterPrefixDirectories($this->readComposerMap('autoload_psr4.php'));
    }

    /** @return array<string, string[]> */
    protected function buildPsr0Prefixes(): array
    {
        return $this->filterPrefixDirectories($this->readComposerMap('autoload_namespaces.php'));
    }

    /** @return string[] */
    protected function buildAutoloadFiles(): array
    {
        return array_values(array_filter($this->readComposerMap('autoload_files.php'), [$this, 'isLoadablePath']));
    }

    /**
     * @param array<string, string[]> $directoriesByPrefix
     * @return array<string, string[]>
     */
    protected function filterPrefixDirectories(array $directoriesByPrefix): array
    {
        $keptDirectoriesByPrefix = [];
        foreach ($directoriesByPrefix as $prefix => $directories) {
            if (str_starts_with((string)$prefix, self::APPLICATION_ROOT_NAMESPACE)) {
                continue;
            }
            $keptDirectories = array_values(array_filter((array)$directories, [$this, 'isLoadablePath']));
            if ($keptDirectories !== []) {
                $keptDirectoriesByPrefix[$prefix] = $keptDirectories;
            }
        }
        return $keptDirectoriesByPrefix;
    }

    /** Whether a path may be loaded: inside the vendor directory, and not the vendor copy of this package. */
    public function isLoadablePath(string $path): bool
    {
        $normalizedPath = $this->normalizePath($path);
        return str_starts_with($normalizedPath, $this->vendorDirectory . '/')
            && !str_starts_with($normalizedPath, $this->getVendorCopyOfPackageDirectory() . '/');
    }

    protected function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }
        return '/' . implode('/', $segments);
    }

    /** @return array<mixed> The array a generated Composer map file returns */
    protected function readComposerMap(string $fileName): array
    {
        $mapFile = $this->vendorDirectory . '/composer/' . $fileName;
        if (!is_file($mapFile)) {
            return [];
        }
        return (array)require $mapFile;
    }
}
