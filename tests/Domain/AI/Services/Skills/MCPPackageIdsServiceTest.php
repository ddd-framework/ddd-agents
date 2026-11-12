<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\MCPPackageIdsService;
use DDD\Presentation\Mcp\Client\AI\AgentMandatesMCPPackage;
use DDD\Presentation\Mcp\Client\AI\AgentTaskRunReportsMCPPackage;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Package ids in the skill binding: the package's own MCP packages are found by id in its source tree, a binding that
 * names ids resolves to classes (class names pass unchanged, nothing autoloaded for them), an unknown id is a
 * configuration error.
 */
class MCPPackageIdsServiceTest extends TestCase
{
    public function testThePackagesOwnMcpPackagesAreFoundInItsSourceTree(): void
    {
        $packageSourceDirectory = dirname(__DIR__, 5) . '/src';
        $packageClasses = (new MCPPackageIdsService())->findPackageClassesInSourceRoot('DDD', $packageSourceDirectory);
        self::assertContains(AgentMandatesMCPPackage::class, $packageClasses);
        self::assertContains(AgentTaskRunReportsMCPPackage::class, $packageClasses);
    }

    public function testAnIdNamesItsPackageClass(): void
    {
        $mCPPackageIdsService = new MCPPackageIdsService();
        self::assertSame(AgentMandatesMCPPackage::class, $mCPPackageIdsService->findPackageClassForId('agent.mandates'));
        self::assertSame(AgentTaskRunReportsMCPPackage::class, $mCPPackageIdsService->findPackageClassForId('agent.taskRunReports'));
        self::assertNull($mCPPackageIdsService->findPackageClassForId('agent.noSuchPackage'));
        self::assertArrayNotHasKey('agent.mandates', $mCPPackageIdsService->getPackageClassesByDuplicateId());
    }

    public function testABindingResolvesIdsAndKeepsClassNames(): void
    {
        self::assertSame(
            ['Assistant.Mandates' => [AgentMandatesMCPPackage::class], 'Mixed' => ['Some\\Class\\NotLoaded', AgentTaskRunReportsMCPPackage::class], 'Empty' => []],
            AISkill::resolvePackageIdsOfBinding(['Assistant.Mandates' => ['agent.mandates'], 'Mixed' => ['Some\\Class\\NotLoaded', 'agent.taskRunReports'], 'Empty' => []])
        );
    }

    public function testAnUnknownIdInTheBindingIsAConfigurationError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("'agent.noSuchPackage'");
        AISkill::resolvePackageIdsOfBinding(['Assistant.Mandates' => ['agent.noSuchPackage']]);
    }

    public function testThePackagesDefaultBindingNamesItsPackagesById(): void
    {
        $defaultBinding = require dirname(__DIR__, 5) . '/config/app/AI/skillPackages.php';
        self::assertSame(
            [AISkill::ASSISTANT_MANDATES => [AgentMandatesMCPPackage::class], AISkill::ASSISTANT_TASK_RUN_REPORTS => [AgentTaskRunReportsMCPPackage::class]],
            AISkill::resolvePackageIdsOfBinding($defaultBinding)
        );
    }
}
