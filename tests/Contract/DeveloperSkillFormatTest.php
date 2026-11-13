<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Support\DeveloperSkillFiles;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The format every developer skill of the package keeps, so that it routes and loads in every application:
 *
 * - `SKILL.md`: frontmatter a strict YAML parser reads, a name equal to the folder, a description of at most
 *   {@see self::MAXIMUM_DESCRIPTION_CHARACTERS} characters (the listing shows nothing else), a body of at most
 *   {@see self::MAXIMUM_SKILL_BODY_LINES} lines;
 * - references one level deep, each naming its reader in line 3, each above {@see self::CONTENTS_LIST_ABOVE_LINES}
 *   lines with a contents list, each linked from `SKILL.md`;
 * - `design-decisions.md`: one fixed entry shape;
 * - no line numbers and no link into another skill's folder (another skill is named, never addressed by path).
 */
class DeveloperSkillFormatTest extends UnitTestCase
{
    public const int MAXIMUM_DESCRIPTION_CHARACTERS = 1024;

    public const int MAXIMUM_SKILL_BODY_LINES = 300;

    public const int CONTENTS_LIST_ABOVE_LINES = 100;

    public const string MODULE_NAME = 'mgamadeus/ddd-agents';

    /** @var string[] The line a reference carries in line 3 */
    public const array READER_LINES = [
        '**Reader:** host developer',
        '**Reader:** package maintainer',
        '**Reader:** host developer and package maintainer',
    ];

    /** @var string[] The paragraph leads of one design decision, in this order */
    public const array DESIGN_DECISION_PARAGRAPH_LEADS = ['**Rule.**', '**Reason.**', '**Rejected.**', '**Would change if.**'];

    public const string DESIGN_DECISIONS_FILE = 'references/design-decisions.md';

    public function testEverySkillOfTheFamilyExists(): void
    {
        $missingSkillNames = array_diff(DeveloperSkillFiles::SKILL_NAMES_BY_ID, array_keys(DeveloperSkillFiles::findSkillDirectories()));
        self::assertSame([], array_values($missingSkillNames));
    }

    public function testTheFrontmatterIsStrictYamlWithNameAndDescription(): void
    {
        $defects = [];
        foreach (DeveloperSkillFiles::findSkillDirectories() as $skillName => $skillDirectory) {
            $skillFileContent = (string)@file_get_contents($skillDirectory . '/SKILL.md');
            $frontmatterText = DeveloperSkillFiles::readFrontmatterText($skillFileContent);
            if ($frontmatterText === null) {
                $defects[] = $skillName . ': no frontmatter';
                continue;
            }
            try {
                $frontmatter = Yaml::parse($frontmatterText);
            } catch (ParseException $parseException) {
                $defects[] = $skillName . ': ' . $parseException->getMessage();
                continue;
            }
            if (($frontmatter['name'] ?? null) !== $skillName) {
                $defects[] = $skillName . ': name is not the folder name';
            }
            $description = $frontmatter['description'] ?? null;
            if (!is_string($description) || trim($description) === '') {
                $defects[] = $skillName . ': no description';
                continue;
            }
            if (mb_strlen($description) > self::MAXIMUM_DESCRIPTION_CHARACTERS) {
                $defects[] = $skillName . ': description has ' . mb_strlen($description) . ' characters';
            }
            preg_match('~^description:[ \t]*(.*)$~m', $frontmatterText, $rawDescriptionMatch);
            $rawDescription = $rawDescriptionMatch[1] ?? '';
            if (!str_starts_with($rawDescription, '"')) {
                $defects[] = $skillName . ': description is not double-quoted';
            }
            if (str_contains($description, '#[') || str_contains($description, '<') || str_contains($description, "\n")) {
                $defects[] = $skillName . ': description holds attribute syntax, a tag or a line break';
            }
            if (!str_contains($description, 'Use when')) {
                $defects[] = $skillName . ': description names no "Use when" triggers';
            }
            if (($frontmatter['metadata']['module'] ?? null) !== self::MODULE_NAME) {
                $defects[] = $skillName . ': metadata.module is not ' . self::MODULE_NAME;
            }
            if (!is_string($frontmatter['metadata']['version'] ?? null)) {
                $defects[] = $skillName . ': metadata.version is not a quoted version';
            }
        }
        self::assertSame([], $defects);
    }

    public function testTheSkillBodyStaysShort(): void
    {
        $tooLongBodies = [];
        foreach (DeveloperSkillFiles::findSkillDirectories() as $skillName => $skillDirectory) {
            $bodyLineCount = count(DeveloperSkillFiles::readBodyLines((string)@file_get_contents($skillDirectory . '/SKILL.md')));
            if ($bodyLineCount > self::MAXIMUM_SKILL_BODY_LINES) {
                $tooLongBodies[] = $skillName . ': ' . $bodyLineCount . ' lines';
            }
        }
        self::assertSame([], $tooLongBodies);
    }

    public function testASkillFolderHoldsOnlyTheSkillFileReferencesAndTemplates(): void
    {
        $misplacedFiles = [];
        foreach (DeveloperSkillFiles::findSkillDirectories() as $skillName => $skillDirectory) {
            foreach (array_keys(DeveloperSkillFiles::findFilesBelow($skillDirectory)) as $relativePath) {
                $isSkillFile = $relativePath === 'SKILL.md';
                $isReference = preg_match('~^references/[^/]+\.(?:md|tsv)$~', $relativePath) === 1;
                $isTemplate = str_starts_with($relativePath, 'templates/');
                if (!$isSkillFile && !$isReference && !$isTemplate) {
                    $misplacedFiles[] = $skillName . '/' . $relativePath;
                }
            }
        }
        self::assertSame([], $misplacedFiles, 'references are one level deep; nothing else lives in a skill folder');
    }

    public function testEveryReferenceNamesItsReaderAndListsItsContentsWhenLong(): void
    {
        $defects = [];
        foreach (DeveloperSkillFiles::findSkillDirectories() as $skillName => $skillDirectory) {
            foreach (DeveloperSkillFiles::findMarkdownFiles($skillDirectory) as $relativePath => $absolutePath) {
                if (!str_starts_with($relativePath, 'references/')) {
                    continue;
                }
                $lines = explode("\n", rtrim((string)file_get_contents($absolutePath), "\n"));
                if (!str_starts_with($lines[0] ?? '', '# ')) {
                    $defects[] = $skillName . '/' . $relativePath . ': line 1 is not the title';
                }
                if (!in_array(trim($lines[2] ?? ''), self::READER_LINES, true)) {
                    $defects[] = $skillName . '/' . $relativePath . ': line 3 does not name the reader';
                }
                if (count($lines) > self::CONTENTS_LIST_ABOVE_LINES && !static::hasContentsList($lines)) {
                    $defects[] = $skillName . '/' . $relativePath . ': ' . count($lines) . ' lines and no contents list';
                }
            }
        }
        self::assertSame([], $defects);
    }

    public function testEveryReferenceAndTemplateIsLinkedAndEveryLinkResolves(): void
    {
        $defects = [];
        foreach (DeveloperSkillFiles::findSkillDirectories() as $skillName => $skillDirectory) {
            $linkedPaths = [];
            foreach (DeveloperSkillFiles::findMarkdownFiles($skillDirectory) as $relativePath => $absolutePath) {
                $linkingDirectory = dirname($relativePath) === '.' ? '' : dirname($relativePath) . '/';
                foreach (DeveloperSkillFiles::findLinkTargets((string)file_get_contents($absolutePath)) as $linkTarget) {
                    if (preg_match('~^[a-z]+:~', $linkTarget) === 1) {
                        continue; // an absolute URL
                    }
                    if (str_starts_with($linkTarget, '../') && $linkingDirectory === '') {
                        $defects[] = $skillName . '/' . $relativePath . ': link out of the skill folder: ' . $linkTarget;
                        continue;
                    }
                    $linkedPath = static::normalizeRelativePath($linkingDirectory . $linkTarget);
                    if ($linkedPath === null) {
                        $defects[] = $skillName . '/' . $relativePath . ': link out of the skill folder: ' . $linkTarget;
                        continue;
                    }
                    if (!file_exists($skillDirectory . '/' . $linkedPath)) {
                        $defects[] = $skillName . '/' . $relativePath . ': dead link ' . $linkTarget;
                        continue;
                    }
                    $linkedPaths[$linkedPath] = true;
                }
            }
            foreach (array_keys(DeveloperSkillFiles::findFilesBelow($skillDirectory)) as $relativePath) {
                if ($relativePath === 'SKILL.md' || isset($linkedPaths[$relativePath])) {
                    continue;
                }
                $isInsideLinkedFolder = false;
                foreach (array_keys($linkedPaths) as $linkedPath) {
                    $isInsideLinkedFolder = $isInsideLinkedFolder || str_starts_with($relativePath, rtrim($linkedPath, '/') . '/');
                }
                if (!$isInsideLinkedFolder) {
                    $defects[] = $skillName . '/' . $relativePath . ': no skill text links it';
                }
            }
        }
        self::assertSame([], $defects);
    }

    public function testEveryDesignDecisionHasTheFixedShape(): void
    {
        $defects = [];
        foreach (DeveloperSkillFiles::findSkillDirectories() as $skillName => $skillDirectory) {
            $designDecisionsFile = $skillDirectory . '/' . self::DESIGN_DECISIONS_FILE;
            if (!is_file($designDecisionsFile)) {
                continue;
            }
            $entries = preg_split('~^## ~m', DeveloperSkillFiles::removeFencedCodeBlocks((string)file_get_contents($designDecisionsFile))) ?: [];
            array_shift($entries); // the text above the first entry
            if ($entries === []) {
                $defects[] = $skillName . ': no design decision';
            }
            foreach ($entries as $entry) {
                $entryTitle = trim(explode("\n", $entry, 2)[0]);
                if (strcasecmp($entryTitle, 'Contents') === 0) {
                    continue;
                }
                $searchOffset = 0;
                foreach (self::DESIGN_DECISION_PARAGRAPH_LEADS as $paragraphLead) {
                    $leadPosition = strpos($entry, "\n" . $paragraphLead, $searchOffset);
                    if ($leadPosition === false) {
                        $defects[] = $skillName . ': "' . $entryTitle . '" lacks ' . $paragraphLead . ' (or has it out of order)';
                        break;
                    }
                    $searchOffset = $leadPosition + 1;
                }
            }
        }
        self::assertSame([], $defects);
    }

    public function testNoSkillTextCarriesALineNumber(): void
    {
        $lineNumbers = [];
        foreach (DeveloperSkillFiles::findSkillDirectories() as $skillName => $skillDirectory) {
            foreach (DeveloperSkillFiles::findMarkdownFiles($skillDirectory) as $relativePath => $absolutePath) {
                $text = (string)file_get_contents($absolutePath);
                preg_match_all('~[\w/.-]+\.(?:php|md|ts|tsx|yaml|yml|json|neon)(?::|#L)\d+|\blines?\s+\d+\b(?!\s+(?:lines|characters|of the file))~i', $text, $lineNumberMatches);
                foreach ($lineNumberMatches[0] as $lineNumberMatch) {
                    // the format rules themselves say "line 1", "line 3"
                    if (preg_match('~^lines?\s+[13]$~i', $lineNumberMatch) === 1) {
                        continue;
                    }
                    $lineNumbers[] = $skillName . '/' . $relativePath . ': ' . $lineNumberMatch;
                }
            }
        }
        self::assertSame([], $lineNumbers, 'a line number is stale with the next commit: name the class and the method');
    }

    /**
     * @param string[] $lines
     */
    protected static function hasContentsList(array $lines): bool
    {
        foreach (array_slice($lines, 3, 12, true) as $lineIndex => $line) {
            if (preg_match('~^(?:#{2,3}\s+|\*\*)?(?:Contents|Table of contents)\b~i', $line) !== 1) {
                continue;
            }
            // one line (or wrapped lines) of entries separated by a middle dot …
            $contentsParagraph = '';
            foreach (array_slice($lines, $lineIndex, 12) as $paragraphLine) {
                if (trim($paragraphLine) === '') {
                    break;
                }
                $contentsParagraph .= ' ' . $paragraphLine;
            }
            if (substr_count($contentsParagraph, ' · ') >= 1) {
                return true;
            }
            // … or a list below the word
            $listItemCount = 0;
            foreach (array_slice($lines, $lineIndex + 1, 60) as $followingLine) {
                if (preg_match('~^\s*(?:[-*]|\d+\.)\s+~', $followingLine) === 1) {
                    $listItemCount++;
                } elseif (trim($followingLine) !== '' && $listItemCount > 0) {
                    break;
                }
            }
            return $listItemCount >= 2;
        }
        return false;
    }

    /** The path with `.` and `..` segments resolved, or null when it leaves the folder it is relative to */
    protected static function normalizeRelativePath(string $relativePath): ?string
    {
        $resolvedSegments = [];
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($resolvedSegments === []) {
                    return null;
                }
                array_pop($resolvedSegments);
                continue;
            }
            $resolvedSegments[] = $segment;
        }
        return implode('/', $resolvedSegments);
    }
}
