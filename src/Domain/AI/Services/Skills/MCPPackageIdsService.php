<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Skills;

use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * The MCP package ids: every MCP package names itself with its static `MCPPackage::$name`
 * (`agent.mandates`), so a skill binding (`AI.skillPackages`) can name a package by id instead of by class — a module's
 * default binding can then name the packages of its own source tree, and an application can replace a package class
 * without rewriting the binding.
 *
 * The packages are found in the source trees: the application's `src/` first, then every DDD module's source path, each
 * below `Presentation/Mcp`, file names ending in `MCPPackage.php`. The first class holding an id wins — the
 * application's package replaces a module's package of the same id. Read once per process (code metadata).
 */
class MCPPackageIdsService extends Service
{
    /** @var string The folder below a source root that holds the MCP packages. */
    public const string PACKAGES_FOLDER = 'Presentation/Mcp';

    /** @var string The file-name ending of an MCP package. */
    public const string PACKAGE_FILE_SUFFIX = 'MCPPackage.php';

    /** @var array<string, class-string<MCPPackage>>|null package id => package class, read once per process */
    protected static ?array $packageClassesById = null;

    /** @var array<string, string[]>|null package id => every class naming it, when more than one does */
    protected static ?array $packageClassesByDuplicateId = null;

    /**
     * The package class named by an id, or null for an unknown id.
     *
     * @return class-string<MCPPackage>|null
     */
    public function findPackageClassForId(string $packageId): ?string
    {
        return $this->getPackageClassesById()[$packageId] ?? null;
    }

    /**
     * package id => package class of every MCP package in the source trees (the first class holding an id).
     *
     * @return array<string, class-string<MCPPackage>>
     */
    public function getPackageClassesById(): array
    {
        if (self::$packageClassesById === null) {
            $this->buildPackageClassesById();
        }
        return self::$packageClassesById;
    }

    /**
     * The ids more than one package class names (the first one wins; the others are never reached by id). An
     * application package extending the module's package of the same id is the intended replacement and not listed.
     *
     * @return array<string, string[]>
     */
    public function getPackageClassesByDuplicateId(): array
    {
        if (self::$packageClassesByDuplicateId === null) {
            $this->buildPackageClassesById();
        }
        return self::$packageClassesByDuplicateId;
    }

    /** Scans the source trees once and fills both memos. */
    protected function buildPackageClassesById(): void
    {
        $packageClassesById = [];
        $packageClassesByDuplicateId = [];
        foreach ($this->getSourceRoots()->getElements() as $agentSourceRoot) {
            foreach ($this->findPackageClassesInSourceRoot(rtrim($agentSourceRoot->rootNamespace, '\\'), $agentSourceRoot->directory) as $packageClass) {
                $packageId = (string)($packageClass::$name ?? '');
                if ($packageId === '') {
                    continue;
                }
                if (!isset($packageClassesById[$packageId])) {
                    $packageClassesById[$packageId] = $packageClass;
                    continue;
                }
                $firstPackageClass = $packageClassesById[$packageId];
                if ($firstPackageClass === $packageClass || is_subclass_of($firstPackageClass, $packageClass)) {
                    continue;
                }
                $packageClassesByDuplicateId[$packageId] ??= [$firstPackageClass];
                $packageClassesByDuplicateId[$packageId][] = $packageClass;
            }
        }
        self::$packageClassesById = $packageClassesById;
        self::$packageClassesByDuplicateId = $packageClassesByDuplicateId;
    }

    /**
     * The source roots to scan: the roots of the discovery contract ({@see AgentSourceRootsService}) — the
     * application's `src/` first, then each DDD module's source path in module order (every DDD module shares the
     * `DDD` root namespace).
     *
     * @return AgentSourceRoots
     */
    protected function getSourceRoots(): AgentSourceRoots
    {
        /** @var AgentSourceRootsService $agentSourceRootsService */
        $agentSourceRootsService = DDDService::instance()->getService(AgentSourceRootsService::class);
        return $agentSourceRootsService->getSourceRoots();
    }

    /**
     * The concrete MCP package classes below `<source root>/Presentation/Mcp`, sorted by file path.
     *
     * @return array<int, class-string<MCPPackage>>
     */
    public function findPackageClassesInSourceRoot(string $rootNamespace, string $sourceDirectory): array
    {
        $packagesDirectory = rtrim($sourceDirectory, '/') . '/' . self::PACKAGES_FOLDER;
        if (!is_dir($packagesDirectory)) {
            return [];
        }
        $packageFilePaths = [];
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packagesDirectory, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), self::PACKAGE_FILE_SUFFIX)) {
                $packageFilePaths[] = $file->getPathname();
            }
        }
        sort($packageFilePaths);
        $packageClasses = [];
        $sourceDirectoryLength = strlen(rtrim($sourceDirectory, '/')) + 1;
        foreach ($packageFilePaths as $packageFilePath) {
            $packageClass = $rootNamespace . '\\' . str_replace('/', '\\', substr($packageFilePath, $sourceDirectoryLength, -4));
            if (!class_exists($packageClass) || !is_subclass_of($packageClass, MCPPackage::class)) {
                continue;
            }
            if ((new ReflectionClass($packageClass))->isAbstract()) {
                continue;
            }
            $packageClasses[] = $packageClass;
        }
        return $packageClasses;
    }
}
