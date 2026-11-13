<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Infrastructure\Modules\DDDModule;
use DDD\Modules\AI\AIModule;
use DDD\Modules\Argus\ArgusModule;
use DDD\Tests\Agents\Support\DeveloperSkillDeclaredNames;
use DDD\Tests\Agents\Support\DeveloperSkillFiles;
use DDD\Tests\Agents\Support\PackageTestAutoloader;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * Every name a developer skill writes in backticks exists: a class, method, constant, property, config key, tool,
 * widget type, agent skill, purpose, file or folder of the package, the framework, the AI or Argus module, or the
 * reference kernel ({@see DeveloperSkillDeclaredNames}). A rename or a move in the code therefore fails this test
 * until the skill that describes it is corrected.
 *
 * A name that is deliberately no declaration (a tool of the operating system, a name the HOST chooses in an example)
 * stands in {@see self::ALLOWLIST_FILE} with its reason: `<name><TAB><reason>`. The list is for such names only — a
 * package name that fails is corrected in the text.
 */
class DeveloperSkillNamesExistTest extends UnitTestCase
{
    public const string ALLOWLIST_FILE = __DIR__ . '/developer-skill-names-allowlist.txt';

    public function testEveryBacktickedNameIsDeclared(): void
    {
        $declaredNames = DeveloperSkillDeclaredNames::fromSourceRoots(static::findSourceRootDirectories(), static::findComposerClassMapFile());
        foreach (DeveloperSkillFiles::SKILL_NAMES_BY_ID as $skillName) {
            $declaredNames->fileAndDirectoryNames[$skillName] = true;
        }
        $allowedNames = static::readAllowedNames();
        $usedAllowedNames = [];

        $namesNotDeclared = [];
        foreach (DeveloperSkillFiles::findSkillDirectories() as $skillName => $skillDirectory) {
            // the file names of the skill's own templates are the names a host gives the copied classes
            $templateNames = [];
            foreach (array_keys(DeveloperSkillFiles::findFilesBelow($skillDirectory . '/templates')) as $templatePath) {
                $templateNames[explode('.', basename($templatePath), 2)[0]] = true;
            }
            foreach (DeveloperSkillFiles::findMarkdownFiles($skillDirectory) as $relativePath => $absolutePath) {
                $codeSpans = array_unique(DeveloperSkillFiles::findInlineCodeSpans((string)file_get_contents($absolutePath)));
                foreach ($codeSpans as $codeSpan) {
                    if (isset($allowedNames[$codeSpan])) {
                        $usedAllowedNames[$codeSpan] = true;
                        continue;
                    }
                    if (isset($templateNames[$codeSpan])) {
                        continue;
                    }
                    $reason = $declaredNames->findWhyNotDeclared($codeSpan, [$skillDirectory, dirname($absolutePath), DeveloperSkillFiles::getSkillsDirectory()]);
                    if ($reason !== null) {
                        $namesNotDeclared[] = $skillName . '/' . $relativePath . ': `' . $codeSpan . '` — ' . $reason;
                    }
                }
            }
        }
        self::assertSame([], $namesNotDeclared, 'correct the text, or — for a name that is deliberately no declaration — add it to the allowlist with its reason');
        self::assertSame([], array_values(array_diff(array_keys($allowedNames), array_keys($usedAllowedNames))), 'an allowlist line no text uses any more is removed');
    }

    public function testEveryAllowlistLineCarriesAReason(): void
    {
        $linesWithoutReason = [];
        foreach (@file(self::ALLOWLIST_FILE, FILE_IGNORE_NEW_LINES) ?: [] as $lineIndex => $line) {
            if (trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }
            $fields = explode("\t", $line, 2);
            if (count($fields) !== 2 || trim($fields[1]) === '') {
                $linesWithoutReason[] = 'line ' . ($lineIndex + 1) . ': ' . $line;
            }
        }
        self::assertSame([], $linesWithoutReason);
    }

    /** @return array<string, string> allowed name => reason */
    protected static function readAllowedNames(): array
    {
        $reasonsByAllowedName = [];
        foreach (@file(self::ALLOWLIST_FILE, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }
            $fields = explode("\t", $line, 2);
            $reasonsByAllowedName[$fields[0]] = $fields[1] ?? '';
        }
        return $reasonsByAllowedName;
    }

    /** @return string[] the package, the framework, the AI module and the Argus module, each as its root folder */
    protected static function findSourceRootDirectories(): array
    {
        return [
            DeveloperSkillFiles::getPackageDirectory(),
            dirname((string)(new ReflectionClass(DDDModule::class))->getFileName(), 4),
            dirname((string)realpath(AIModule::getSourcePath())),
            dirname((string)realpath(ArgusModule::getSourcePath())),
        ];
    }

    protected static function findComposerClassMapFile(): string
    {
        return PackageTestAutoloader::forPackageDirectory(DeveloperSkillFiles::getPackageDirectory())->getVendorDirectory() . '/composer/autoload_classmap.php';
    }
}
