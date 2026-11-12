<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Services\Skills\AISkillSourcesService;
use PHPUnit\Framework\TestCase;

/**
 * The skill-source registry: the config roots holding skill / purpose folders in search order (the package's own root
 * among them), the dotted names of a folder, which names an earlier root overrides and which names collide.
 */
class AISkillSourcesServiceTest extends TestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . '/ddd-agents-skill-sources-' . bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory . '/Assistant', 0777, true);
        mkdir($this->temporaryDirectory . '/Web/Nested', 0777, true);
        file_put_contents($this->temporaryDirectory . '/Assistant/Mandates.md', "---\ndescription: x\n---\n");
        file_put_contents($this->temporaryDirectory . '/Web/Nested/Deep.md', "---\ndescription: x\n---\n");
        file_put_contents($this->temporaryDirectory . '/Web/notes.txt', 'not a skill');
    }

    protected function tearDown(): void
    {
        foreach (['/Assistant/Mandates.md', '/Web/Nested/Deep.md', '/Web/notes.txt'] as $relativeFile) {
            @unlink($this->temporaryDirectory . $relativeFile);
        }
        foreach (['/Web/Nested', '/Web', '/Assistant', ''] as $relativeDirectory) {
            @rmdir($this->temporaryDirectory . $relativeDirectory);
        }
        parent::tearDown();
    }

    public function testTheDottedNamesOfAFolderAreItsMarkdownFilesRelativeToIt(): void
    {
        $aISkillSourcesService = new AISkillSourcesService();
        self::assertSame(['Assistant.Mandates', 'Web.Nested.Deep'], $aISkillSourcesService->findDottedNamesInFolder($this->temporaryDirectory));
        self::assertSame([], $aISkillSourcesService->findDottedNamesInFolder($this->temporaryDirectory . '/missing'));
    }

    public function testThePackagesOwnSkillAndPurposeFoldersAreRegisteredRoots(): void
    {
        $aISkillSourcesService = new AISkillSourcesService();
        $packageConfigDirectory = realpath(dirname(__DIR__, 5) . '/config/app');
        $skillRootDirectories = array_map('realpath', $aISkillSourcesService->getSkillRootDirectories());
        $purposeRootDirectories = array_map('realpath', $aISkillSourcesService->getPurposeRootDirectories());
        self::assertContains($packageConfigDirectory . '/AI/Skills', $skillRootDirectories);
        self::assertContains($packageConfigDirectory . '/AI/Purposes', $purposeRootDirectories);
        self::assertContains('Assistant.Mandates', $aISkillSourcesService->findDottedNamesInFolder($packageConfigDirectory . '/AI/Skills'));
        self::assertSame($aISkillSourcesService->getConfigRootDirectories(), array_values(array_unique($aISkillSourcesService->getConfigRootDirectories())));
    }

    public function testASameNameInTwoRootsIsAnOverrideNotACollision(): void
    {
        $aISkillSourcesService = new AISkillSourcesService();
        $namesByRoot = ['app' => ['Assistant.Mandates', 'Seo.Rankings'], 'module' => ['Assistant.Mandates', 'Assistant.TaskRunReports']];
        self::assertSame(['Assistant.Mandates' => ['app', 'module']], $aISkillSourcesService->findOverriddenNames($namesByRoot));
        self::assertSame([], $aISkillSourcesService->findCollidingNames($namesByRoot));
    }

    public function testNamesThatDifferOnlyInCaseCollideWithinAndAcrossRoots(): void
    {
        $aISkillSourcesService = new AISkillSourcesService();
        self::assertSame(
            ['assistant.mandates' => ['app: Assistant.Mandates', 'module: Assistant.mandates']],
            $aISkillSourcesService->findCollidingNames(['app' => ['Assistant.Mandates'], 'module' => ['Assistant.mandates']])
        );
        self::assertSame(
            ['web.search' => ['app: Web.Search', 'app: Web.search']],
            $aISkillSourcesService->findCollidingNames(['app' => ['Web.Search', 'Web.search']])
        );
    }
}
