<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Skills;

use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Where skill and purpose files come from: every config root of the running application that holds an
 * `AI/Skills` (or `AI/Purposes`) folder, in the order {@see Config} searches them — the application's roots first, then
 * the modules' (this package ships its own skills and purposes in its `config/app`).
 *
 * Reading a skill stays `Config::get('AI.Skills.<name>')` ({@see AISkillsService::getAISkillByName()}): the first root
 * holding the file wins, so an application file at a module's path REPLACES the module's file (an override, never a
 * merge). This registry only lists the files — for the skill-file lint and the catalog tests: which names each root
 * holds, which names an application overrides, and which names collide.
 */
class AISkillSourcesService extends Service
{
    /** @var string The folder below a config root that holds the skill files. */
    public const string SKILLS_FOLDER = 'AI/Skills';

    /** @var string The folder below a config root that holds the purpose files. */
    public const string PURPOSES_FOLDER = 'AI/Purposes';

    /**
     * Every config root directory in search order (application roots first, then module roots).
     *
     * @return string[]
     */
    public function getConfigRootDirectories(): array
    {
        return array_values(Config::getConfigRootDirectories());
    }

    /**
     * The skill folders of every config root that holds one, in search order.
     *
     * @return string[]
     */
    public function getSkillRootDirectories(): array
    {
        return $this->findFoldersBelowConfigRoots(self::SKILLS_FOLDER);
    }

    /**
     * The purpose folders of every config root that holds one, in search order.
     *
     * @return string[]
     */
    public function getPurposeRootDirectories(): array
    {
        return $this->findFoldersBelowConfigRoots(self::PURPOSES_FOLDER);
    }

    /**
     * The existing `<config root>/<folder>` directories, in search order.
     *
     * @return string[]
     */
    public function findFoldersBelowConfigRoots(string $folderBelowConfigRoot): array
    {
        $foldersBelowConfigRoots = [];
        foreach ($this->getConfigRootDirectories() as $configRootDirectory) {
            $folderPath = rtrim($configRootDirectory, '/') . '/' . $folderBelowConfigRoot;
            if (is_dir($folderPath)) {
                $foldersBelowConfigRoots[] = $folderPath;
            }
        }
        return $foldersBelowConfigRoots;
    }

    /**
     * The dotted names of the `.md` files below one skill (or purpose) folder, sorted: `Assistant/Budget.md` →
     * `Assistant.Budget`.
     *
     * @return string[]
     */
    public function findDottedNamesInFolder(string $folderPath): array
    {
        if (!is_dir($folderPath)) {
            return [];
        }
        $dottedNames = [];
        $folderPathLength = strlen(rtrim($folderPath, '/')) + 1;
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folderPath, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $relativePathWithoutExtension = substr($file->getPathname(), $folderPathLength, -3);
            $dottedNames[] = str_replace('/', '.', $relativePathWithoutExtension);
        }
        sort($dottedNames);
        return $dottedNames;
    }

    /**
     * skill folder => the dotted skill names it holds, in search order.
     *
     * @return array<string, string[]>
     */
    public function findSkillNamesByRoot(): array
    {
        $skillNamesByRoot = [];
        foreach ($this->getSkillRootDirectories() as $skillRootDirectory) {
            $skillNamesByRoot[$skillRootDirectory] = $this->findDottedNamesInFolder($skillRootDirectory);
        }
        return $skillNamesByRoot;
    }

    /**
     * purpose folder => the dotted purpose names it holds, in search order.
     *
     * @return array<string, string[]>
     */
    public function findPurposeNamesByRoot(): array
    {
        $purposeNamesByRoot = [];
        foreach ($this->getPurposeRootDirectories() as $purposeRootDirectory) {
            $purposeNamesByRoot[$purposeRootDirectory] = $this->findDottedNamesInFolder($purposeRootDirectory);
        }
        return $purposeNamesByRoot;
    }

    /**
     * The names that COLLIDE: {@see Config} resolves a name case-insensitively, so two files whose names differ only in
     * case are the same name — within one root they are two files for one name (which one loads depends on the
     * directory order), across roots a differently cased file shadows the other unnoticed. lowercased name => every
     * "<root>: <name>" holding it; empty = no collision.
     *
     * @param array<string, string[]> $namesByRoot root => dotted names ({@see findSkillNamesByRoot()})
     * @return array<string, string[]>
     */
    public function findCollidingNames(array $namesByRoot): array
    {
        $holdersByLowercaseName = [];
        $spellingsByLowercaseName = [];
        foreach ($namesByRoot as $rootDirectory => $dottedNames) {
            foreach ($dottedNames as $dottedName) {
                $lowercaseName = strtolower($dottedName);
                $holdersByLowercaseName[$lowercaseName][] = $rootDirectory . ': ' . $dottedName;
                $spellingsByLowercaseName[$lowercaseName][$rootDirectory][] = $dottedName;
            }
        }
        $collidingNames = [];
        foreach ($spellingsByLowercaseName as $lowercaseName => $spellingsByRoot) {
            $isTwiceInOneRoot = max(array_map('count', $spellingsByRoot)) > 1;
            $hasDifferentSpellings = count(array_unique(array_merge(...array_values($spellingsByRoot)))) > 1;
            if ($isTwiceInOneRoot || $hasDifferentSpellings) {
                $collidingNames[$lowercaseName] = $holdersByLowercaseName[$lowercaseName];
            }
        }
        return $collidingNames;
    }

    /**
     * The names an earlier root OVERRIDES: the same name in more than one root — the first root's file is the one
     * that loads (an application replacing a module's skill). name => the roots holding it, in search order.
     *
     * @param array<string, string[]> $namesByRoot root => dotted names ({@see findSkillNamesByRoot()})
     * @return array<string, string[]>
     */
    public function findOverriddenNames(array $namesByRoot): array
    {
        $rootsByName = [];
        foreach ($namesByRoot as $rootDirectory => $dottedNames) {
            foreach ($dottedNames as $dottedName) {
                $rootsByName[$dottedName][] = $rootDirectory;
            }
        }
        return array_filter($rootsByName, static fn (array $rootDirectories): bool => count($rootDirectories) > 1);
    }
}
