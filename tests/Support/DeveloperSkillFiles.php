<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The package's developer skills as files: where they live, which folders belong to the family, and the parts of a
 * skill text the contract tests read (frontmatter, body, inline code spans, links).
 *
 * The skills are read from `.claude/skills` of the package. {@see self::SKILLS_DIRECTORY_ENV} points the tests at
 * another folder — a draft of the skills that is checked against the package before it is copied in.
 */
class DeveloperSkillFiles
{
    /** Process variable: an absolute folder that holds the skill folders, instead of the package's `.claude/skills` */
    public const string SKILLS_DIRECTORY_ENV = 'DDD_AGENTS_DEVELOPER_SKILLS_DIR';

    /** The entry skill; it also holds the ownership manifest */
    public const string ENTRY_SKILL_NAME = 'ddd-module-agents-specialist';

    /** @var string[] manifest id => skill folder name */
    public const array SKILL_NAMES_BY_ID = [
        'entry' => 'ddd-module-agents-specialist',
        'loop' => 'ddd-module-agents-loop-specialist',
        'context' => 'ddd-module-agents-context-specialist',
        'skills-and-purposes' => 'ddd-module-agents-skills-and-purposes-specialist',
        'tools' => 'ddd-module-agents-tools-specialist',
        'widgets' => 'ddd-module-agents-widgets-specialist',
        'autonomy' => 'ddd-module-agents-autonomy-specialist',
        'decisions-and-guards' => 'ddd-module-agents-decisions-and-guards-specialist',
        'observability' => 'ddd-module-agents-observability-specialist',
    ];

    /** Path of the ownership manifest inside the entry skill */
    public const string OWNERSHIP_MANIFEST = 'references/source-ownership.tsv';

    public static function getPackageDirectory(): string
    {
        return dirname(AgentPackageSourceRoots::getPackageSourceDirectory());
    }

    public static function getSkillsDirectory(): string
    {
        $skillsDirectoryFromEnvironment = getenv(self::SKILLS_DIRECTORY_ENV) ?: null;
        return rtrim($skillsDirectoryFromEnvironment ?? static::getPackageDirectory() . '/.claude/skills', '/');
    }

    public static function getOwnershipManifestPath(): string
    {
        return static::getSkillsDirectory() . '/' . self::ENTRY_SKILL_NAME . '/' . self::OWNERSHIP_MANIFEST;
    }

    /** @return array<string, string> skill folder name => absolute folder, for every family skill that exists */
    public static function findSkillDirectories(): array
    {
        $skillDirectoriesByName = [];
        foreach (self::SKILL_NAMES_BY_ID as $skillName) {
            $skillDirectory = static::getSkillsDirectory() . '/' . $skillName;
            if (is_dir($skillDirectory)) {
                $skillDirectoriesByName[$skillName] = $skillDirectory;
            }
        }
        return $skillDirectoriesByName;
    }

    /** @return array<string, string> path relative to the folder => absolute path, sorted, every file below the folder */
    public static function findFilesBelow(string $directory): array
    {
        $absolutePathsByRelativePath = [];
        if (!is_dir($directory)) {
            return $absolutePathsByRelativePath;
        }
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $file) {
            if ($file->isFile()) {
                $absolutePathsByRelativePath[substr($file->getPathname(), strlen($directory) + 1)] = $file->getPathname();
            }
        }
        ksort($absolutePathsByRelativePath);
        return $absolutePathsByRelativePath;
    }

    /** @return array<string, string> path relative to the skill folder => absolute path, every Markdown file of the skill */
    public static function findMarkdownFiles(string $skillDirectory): array
    {
        $markdownFiles = [];
        foreach (static::findFilesBelow($skillDirectory) as $relativePath => $absolutePath) {
            if (str_ends_with($relativePath, '.md') && !str_starts_with($relativePath, 'templates/')) {
                $markdownFiles[$relativePath] = $absolutePath;
            }
        }
        return $markdownFiles;
    }

    /** The YAML between the two `---` lines at the top of a skill file, or null when the file has none */
    public static function readFrontmatterText(string $skillFileContent): ?string
    {
        if (preg_match('~\A---\n(.*?)\n---\n~s', $skillFileContent, $frontmatterMatch) !== 1) {
            return null;
        }
        return $frontmatterMatch[1];
    }

    /** @return string[] the lines below the frontmatter */
    public static function readBodyLines(string $skillFileContent): array
    {
        $body = (string)preg_replace('~\A---\n.*?\n---\n~s', '', $skillFileContent, 1);
        return explode("\n", rtrim($body, "\n"));
    }

    /** The text with every fenced code block removed (the fence lines and what lies between them) */
    public static function removeFencedCodeBlocks(string $markdown): string
    {
        $keptLines = [];
        $isInsideFence = false;
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('~^\s*(```|\~\~\~)~', $line) === 1) {
                $isInsideFence = !$isInsideFence;
                continue;
            }
            if (!$isInsideFence) {
                $keptLines[] = $line;
            }
        }
        return implode("\n", $keptLines);
    }

    /** @return string[] the content of every inline code span outside fenced code blocks, in reading order */
    public static function findInlineCodeSpans(string $markdown): array
    {
        preg_match_all('~(?<!`)`([^`\n]+)`(?!`)~', static::removeFencedCodeBlocks($markdown), $codeSpanMatches);
        return $codeSpanMatches[1];
    }

    /** @return string[] the target of every Markdown link outside fenced code blocks, without its `#anchor` */
    public static function findLinkTargets(string $markdown): array
    {
        preg_match_all('~\]\(([^)\s]+)\)~', static::removeFencedCodeBlocks($markdown), $linkMatches);
        $linkTargets = [];
        foreach ($linkMatches[1] as $linkTarget) {
            $linkTargetWithoutAnchor = explode('#', $linkTarget, 2)[0];
            if ($linkTargetWithoutAnchor !== '') {
                $linkTargets[] = $linkTargetWithoutAnchor;
            }
        }
        return $linkTargets;
    }
}
