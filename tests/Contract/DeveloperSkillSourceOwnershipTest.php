<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Support\DeveloperSkillFiles;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Every file under `src/` and `config/` belongs to exactly one developer skill: the ownership manifest
 * (`references/source-ownership.tsv` of the entry skill) names it. A new or moved file fails this test until the
 * manifest — and with it the owning skill — is updated in the same change.
 *
 * Manifest: one rule per line, `<glob relative to the package root><TAB><skill id>`; empty lines and lines starting
 * with a hash are skipped; rules are read top to bottom and the FIRST matching rule owns a file. Glob syntax:
 * a double star followed by a slash is zero or more whole path segments, a double star at the end is anything, a
 * single star is anything but a slash, every other character is itself.
 */
class DeveloperSkillSourceOwnershipTest extends UnitTestCase
{
    /** @var string[] The folders of the package whose files need an owner */
    public const array OWNED_ROOT_DIRECTORIES = ['src', 'config'];

    public function testEveryRuleNamesAnExistingSkill(): void
    {
        $malformedLines = [];
        foreach (static::readManifestLines() as $lineNumber => $line) {
            $fields = explode("\t", $line);
            if (count($fields) !== 2 || trim($fields[0]) === '' || !isset(DeveloperSkillFiles::SKILL_NAMES_BY_ID[trim($fields[1])])) {
                $malformedLines[] = 'line ' . $lineNumber . ': ' . $line;
            }
        }
        self::assertSame([], $malformedLines, 'a rule is <glob><TAB><skill id>');
    }

    public function testEveryFileHasAnOwnerAndEveryRuleOwnsAFile(): void
    {
        $rules = [];
        foreach (static::readManifestLines() as $lineNumber => $line) {
            $fields = explode("\t", $line);
            $rules[$lineNumber] = ['pattern' => trim($fields[0]), 'regex' => static::translateGlobToRegex(trim($fields[0])), 'fileCount' => 0];
        }
        self::assertNotSame([], $rules, 'the manifest is missing or empty: ' . DeveloperSkillFiles::getOwnershipManifestPath());

        $filesWithoutOwner = [];
        foreach (self::OWNED_ROOT_DIRECTORIES as $ownedRootDirectory) {
            $absoluteRootDirectory = DeveloperSkillFiles::getPackageDirectory() . '/' . $ownedRootDirectory;
            foreach (array_keys(DeveloperSkillFiles::findFilesBelow($absoluteRootDirectory)) as $pathBelowRoot) {
                $relativePath = $ownedRootDirectory . '/' . $pathBelowRoot;
                $owningLineNumber = null;
                foreach ($rules as $lineNumber => $rule) {
                    if (preg_match($rule['regex'], $relativePath) === 1) {
                        $owningLineNumber = $lineNumber;
                        break;
                    }
                }
                if ($owningLineNumber === null) {
                    $filesWithoutOwner[] = $relativePath;
                    continue;
                }
                $rules[$owningLineNumber]['fileCount']++;
            }
        }
        $rulesWithoutFile = [];
        foreach ($rules as $lineNumber => $rule) {
            if ($rule['fileCount'] === 0) {
                $rulesWithoutFile[] = 'line ' . $lineNumber . ': ' . $rule['pattern'];
            }
        }
        self::assertSame([], $filesWithoutOwner, 'add each file to the manifest and describe it in the owning skill');
        self::assertSame([], $rulesWithoutFile, 'a rule that matches no file is stale: the file moved or is owned by an earlier rule');
    }

    /** @return array<int, string> line number => rule line, without comments and empty lines */
    protected static function readManifestLines(): array
    {
        $ruleLinesByLineNumber = [];
        $manifestLines = @file(DeveloperSkillFiles::getOwnershipManifestPath(), FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($manifestLines as $lineIndex => $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $ruleLinesByLineNumber[$lineIndex + 1] = $line;
        }
        return $ruleLinesByLineNumber;
    }

    protected static function translateGlobToRegex(string $globPattern): string
    {
        $regexParts = [];
        $position = 0;
        $length = strlen($globPattern);
        while ($position < $length) {
            if (substr($globPattern, $position, 3) === '**/') {
                $regexParts[] = '(?:[^/]+/)*';
                $position += 3;
            } elseif (substr($globPattern, $position, 2) === '**') {
                $regexParts[] = '.*';
                $position += 2;
            } elseif ($globPattern[$position] === '*') {
                $regexParts[] = '[^/]*';
                $position++;
            } else {
                $regexParts[] = preg_quote($globPattern[$position], '~');
                $position++;
            }
        }
        return '~^' . implode('', $regexParts) . '$~';
    }
}
