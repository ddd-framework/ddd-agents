<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use PHPUnit\Framework\TestCase;

/**
 * The skill-file lint over the package's own root (clean), and what it reports for a broken file: invalid YAML (an
 * unescaped inner double quote), an unknown key, a list gate that is no list, an unresolvable sub-skill, a missing
 * purpose class.
 */
class AISkillFilesLintServiceTest extends TestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . '/ddd-agents-skill-lint-' . bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory . '/Skills/Broken', 0777, true);
        mkdir($this->temporaryDirectory . '/Purposes/Broken', 0777, true);
        file_put_contents($this->temporaryDirectory . '/Skills/Broken/Yaml.md', "---\ndescription: \"a \"quoted\" word\"\n---\nbody");
        file_put_contents($this->temporaryDirectory . '/Skills/Broken/Keys.md', "---\ndescription: d\nrequiredFeatureFlag: [X]\nrequiredFeatureFlags: X\nsubSkillNames: [No.Such.Skill]\n---\nbody");
        file_put_contents($this->temporaryDirectory . '/Purposes/Broken/Purpose.md', "---\ndescription: d\n---\nbody");
    }

    protected function tearDown(): void
    {
        foreach (['/Skills/Broken/Yaml.md', '/Skills/Broken/Keys.md', '/Purposes/Broken/Purpose.md'] as $relativeFile) {
            @unlink($this->temporaryDirectory . $relativeFile);
        }
        foreach (['/Skills/Broken', '/Skills', '/Purposes/Broken', '/Purposes', ''] as $relativeDirectory) {
            @rmdir($this->temporaryDirectory . $relativeDirectory);
        }
        parent::tearDown();
    }

    public function testThePackagesOwnSkillAndPurposeFilesAreClean(): void
    {
        $packageConfigDirectory = dirname(__DIR__, 5) . '/config/app/AI';
        $aISkillFilesLintService = new AISkillFilesLintService();
        self::assertSame([], $aISkillFilesLintService->findSkillFileProblems([$packageConfigDirectory . '/Skills']));
        self::assertSame([], $aISkillFilesLintService->findPurposeFileProblems([$packageConfigDirectory . '/Purposes']));
    }

    public function testABrokenSkillFileIsReportedWithItsCause(): void
    {
        $problems = (new AISkillFilesLintService())->findSkillFileProblems([$this->temporaryDirectory . '/Skills']);
        $problemsText = implode("\n", $problems);
        self::assertStringContainsString('Yaml.md: the frontmatter is no valid YAML', $problemsText);
        self::assertStringContainsString("Keys.md: unknown frontmatter key 'requiredFeatureFlag'", $problemsText);
        self::assertStringContainsString("Keys.md: 'requiredFeatureFlags' is not a list of strings", $problemsText);
        self::assertStringContainsString("Keys.md: sub-skill 'No.Such.Skill' resolves to no skill file", $problemsText);
        self::assertCount(4, $problems);
    }

    public function testAPurposeFileWithoutAPurposeClassIsReported(): void
    {
        $problems = (new AISkillFilesLintService())->findPurposeFileProblems([$this->temporaryDirectory . '/Purposes']);
        self::assertCount(1, $problems);
        self::assertStringContainsString('Purpose.md: no valid purposeClass', $problems[0]);
    }

    public function testOnlyAListOfStringsIsAList(): void
    {
        $aISkillFilesLintService = new AISkillFilesLintService();
        self::assertTrue($aISkillFilesLintService->isListOfStrings(['A', 'B']));
        self::assertTrue($aISkillFilesLintService->isListOfStrings([]));
        self::assertFalse($aISkillFilesLintService->isListOfStrings('A'));
        self::assertFalse($aISkillFilesLintService->isListOfStrings(['a' => 'A']));
        self::assertFalse($aISkillFilesLintService->isListOfStrings([1]));
    }
}
