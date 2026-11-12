<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Infrastructure\Modules\DDDModule;
use DDD\Modules\AI\AIModule;
use DDD\Modules\Agents\AgentsModule;
use DDD\Modules\Argus\ArgusModule;
use DDD\Modules\Money\MoneyModule;
use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * The module declares exactly the namespaces it owns ({@see AgentsModule::OWNED_NAMESPACES}, listed again in the
 * module's docblock), and owning means: the package has files there, and no other package on the class path (the
 * framework, ddd-ai, ddd-argus, ddd-common-money) has a file at or below it. A class of the same name in another package
 * would otherwise be a silent autoload race.
 */
class OwnedNamespacesTest extends UnitTestCase
{
    public function testEveryPackageClassSitsInExactlyOneOwnedNamespace(): void
    {
        $filesOutsideOwnedNamespaces = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            $className = 'DDD\\' . str_replace('/', '\\', substr($relativePath, 0, -4));
            $owningNamespaces = array_filter(AgentsModule::OWNED_NAMESPACES, static fn (string $ownedNamespace): bool => str_starts_with($className, $ownedNamespace));
            if (count($owningNamespaces) !== 1) {
                $filesOutsideOwnedNamespaces[] = $relativePath . ' (' . count($owningNamespaces) . ' owning namespaces)';
            }
        }
        self::assertSame([], $filesOutsideOwnedNamespaces);
    }

    public function testEveryOwnedNamespaceHoldsPackageFiles(): void
    {
        $emptyOwnedNamespaces = [];
        foreach (AgentsModule::OWNED_NAMESPACES as $ownedNamespace) {
            if (AgentPackageSourceRoots::findPhpFiles(static::toRelativeDirectory($ownedNamespace)) === []) {
                $emptyOwnedNamespaces[] = $ownedNamespace;
            }
        }
        self::assertSame([], $emptyOwnedNamespaces, 'an owned namespace without a file is a stale declaration');
    }

    public function testNoOtherPackageHasAFileInAnOwnedNamespace(): void
    {
        $sharedNamespaces = [];
        foreach (static::findOtherPackageSourceDirectories() as $otherSourceDirectory) {
            foreach (AgentsModule::OWNED_NAMESPACES as $ownedNamespace) {
                $directory = $otherSourceDirectory . '/' . static::toRelativeDirectory($ownedNamespace);
                if (is_dir($directory) && static::holdsPhpFile($directory)) {
                    $sharedNamespaces[] = $ownedNamespace . ' in ' . $otherSourceDirectory;
                }
            }
        }
        self::assertSame([], $sharedNamespaces);
    }

    public function testTheDocblockListsTheDeclaredNamespaces(): void
    {
        $docComment = (string)(new ReflectionClass(AgentsModule::class))->getDocComment();
        preg_match_all('~`((?:DDD|…)\\\\[A-Za-z\\\\]+\\\\)`~', $docComment, $namespaceMatches);
        $listedNamespaces = [];
        foreach ($namespaceMatches[1] as $listedNamespace) {
            // `…\X\` abbreviates `DDD\Domain\AI\X\`
            if (str_starts_with($listedNamespace, '…')) {
                $listedNamespace = 'DDD\\Domain\\AI' . substr($listedNamespace, strlen('…'));
            }
            if (in_array($listedNamespace, AgentsModule::OWNED_NAMESPACES, true)) {
                $listedNamespaces[] = $listedNamespace;
            }
        }
        $listedNamespaces = array_values(array_unique($listedNamespaces));
        sort($listedNamespaces);
        $declaredNamespaces = AgentsModule::OWNED_NAMESPACES;
        sort($declaredNamespaces);
        self::assertSame($declaredNamespaces, $listedNamespaces);
    }

    public function testThePublicServiceNamespacesAreOwned(): void
    {
        $notOwnedNamespaces = [];
        foreach (AgentsModule::getPublicServiceNamespaces() as $publicServiceNamespace) {
            if (array_filter(AgentsModule::OWNED_NAMESPACES, static fn (string $ownedNamespace): bool => str_starts_with($publicServiceNamespace, $ownedNamespace)) === []) {
                $notOwnedNamespaces[] = $publicServiceNamespace;
            }
        }
        self::assertSame([], $notOwnedNamespaces);
    }

    protected static function toRelativeDirectory(string $namespace): string
    {
        return str_replace('\\', '/', substr(rtrim($namespace, '\\'), strlen('DDD\\')));
    }

    /** @return string[] the `src/` directories of the framework and the other modules the package runs with */
    protected static function findOtherPackageSourceDirectories(): array
    {
        $sourceDirectories = [dirname((string)(new ReflectionClass(DDDModule::class))->getFileName(), 3)];
        foreach ([AIModule::class, ArgusModule::class, MoneyModule::class] as $moduleClass) {
            $sourceDirectories[] = (string)realpath($moduleClass::getSourcePath());
        }
        return $sourceDirectories;
    }

    protected static function holdsPhpFile(string $directory): bool
    {
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                return true;
            }
        }
        return false;
    }
}
