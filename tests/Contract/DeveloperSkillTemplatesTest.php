<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Support\DeveloperSkillFiles;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The file templates a host copies out of a skill's `templates/` folder stay usable:
 *
 * - a PHP template names a sample for each of its placeholders in a comment line
 *   `// placeholder {{NAME}} sample: Value`; with the samples filled in it passes the PHP syntax check, and every class
 *   it imports from the `DDD\` namespace is loadable;
 * - a YAML template, and the frontmatter of a Markdown template, parses with every placeholder replaced.
 *
 * A template never claims more than the package can run: a class that moved or was renamed fails here.
 */
class DeveloperSkillTemplatesTest extends UnitTestCase
{
    /** What a placeholder without a declared sample is replaced by in YAML and Markdown templates */
    public const string DEFAULT_SAMPLE = 'Sample';

    public function testEveryPhpTemplatePassesTheSyntaxCheckAndImportsOnlyExistingClasses(): void
    {
        $defects = [];
        foreach (static::findTemplateFiles('php') as $templateName => $templateFile) {
            $template = (string)file_get_contents($templateFile);
            preg_match_all('~^// placeholder \{\{([A-Z0-9_]+)\}\} sample: (.+)$~m', $template, $sampleMatches);
            $samplesByPlaceholder = array_combine($sampleMatches[1], array_map('trim', $sampleMatches[2]));
            preg_match_all('~\{\{([A-Z0-9_]+)\}\}~', $template, $placeholderMatches);
            $placeholdersWithoutSample = array_diff(array_unique($placeholderMatches[1]), array_keys($samplesByPlaceholder));
            if ($placeholdersWithoutSample !== []) {
                $defects[] = $templateName . ': no sample for ' . implode(', ', $placeholdersWithoutSample);
                continue;
            }
            $filledTemplate = $template;
            foreach ($samplesByPlaceholder as $placeholder => $sample) {
                $filledTemplate = str_replace('{{' . $placeholder . '}}', $sample, $filledTemplate);
            }
            $syntaxError = static::findPhpSyntaxError($filledTemplate);
            if ($syntaxError !== null) {
                $defects[] = $templateName . ': ' . $syntaxError;
            }
            preg_match_all('~^use\s+(DDD\\\\[\w\\\\]+)(?:\s+as\s+\w+)?;~m', $filledTemplate, $importMatches);
            foreach ($importMatches[1] as $importedClassName) {
                if (!class_exists($importedClassName) && !interface_exists($importedClassName) && !trait_exists($importedClassName) && !enum_exists($importedClassName)) {
                    $defects[] = $templateName . ': imports ' . $importedClassName . ', which does not exist';
                }
            }
        }
        self::assertSame([], $defects);
    }

    public function testEveryYamlTemplateAndMarkdownFrontmatterParses(): void
    {
        $defects = [];
        $templateFiles = array_merge(static::findTemplateFiles('yaml'), static::findTemplateFiles('yml'), static::findTemplateFiles('md'));
        foreach ($templateFiles as $templateName => $templateFile) {
            $template = (string)preg_replace('~\{\{[A-Z0-9_]+\}\}~', self::DEFAULT_SAMPLE, (string)file_get_contents($templateFile));
            $yamlText = str_ends_with($templateFile, '.md') ? DeveloperSkillFiles::readFrontmatterText($template) : $template;
            if ($yamlText === null) {
                continue; // a Markdown template without frontmatter
            }
            try {
                Yaml::parse($yamlText);
            } catch (ParseException $parseException) {
                $defects[] = $templateName . ': ' . $parseException->getMessage();
            }
        }
        self::assertSame([], $defects);
    }

    /** @return array<string, string> `<skill>/<path>` => absolute path, every template with the extension */
    protected static function findTemplateFiles(string $extension): array
    {
        $templateFilesByName = [];
        foreach (DeveloperSkillFiles::findSkillDirectories() as $skillName => $skillDirectory) {
            foreach (DeveloperSkillFiles::findFilesBelow($skillDirectory . '/templates') as $relativePath => $absolutePath) {
                if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === $extension) {
                    $templateFilesByName[$skillName . '/templates/' . $relativePath] = $absolutePath;
                }
            }
        }
        return $templateFilesByName;
    }

    /** The first line of the PHP syntax check's output when the code does not parse, null when it does */
    protected static function findPhpSyntaxError(string $phpCode): ?string
    {
        $temporaryFile = (string)tempnam(sys_get_temp_dir(), 'developer-skill-template-');
        file_put_contents($temporaryFile, $phpCode);
        try {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temporaryFile) . ' 2>&1', $outputLines, $exitCode);
        } finally {
            unlink($temporaryFile);
        }
        if ($exitCode === 0) {
            return null;
        }
        return str_replace($temporaryFile, 'the filled template', trim(implode(' ', $outputLines)));
    }
}
