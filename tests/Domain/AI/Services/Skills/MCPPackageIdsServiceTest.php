<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsChatMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsDocumentsMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsFilesMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsContactsMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsCalendarMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsMailMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsMCPPackage;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\MCPPackageIdsService;
use DDD\Presentation\Mcp\Client\Agents\AgentMandatesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalBlueprintMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentImagesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentConversationsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentMemoriesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskRunReportsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentUIActionsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentUIMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentUIWindowMCPPackage;
use DDD\Presentation\Mcp\Admin\System\SystemCronsMCPPackage;
use DDD\Presentation\Mcp\Admin\System\SystemHealthMCPPackage;
use DDD\Presentation\Mcp\Admin\System\SystemIssuesMCPPackage;
use DDD\Presentation\Mcp\Admin\System\SystemWorkersMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\WebSearchMCPPackage;
use DDD\Presentation\Mcp\Client\Voice\AgentVoiceCallsMCPPackage;
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
            ['Agent.Mandates' => [AgentMandatesMCPPackage::class], 'Mixed' => ['Some\\Class\\NotLoaded', AgentTaskRunReportsMCPPackage::class], 'Empty' => []],
            AISkill::resolvePackageIdsOfBinding(['Agent.Mandates' => ['agent.mandates'], 'Mixed' => ['Some\\Class\\NotLoaded', 'agent.taskRunReports'], 'Empty' => []])
        );
    }

    public function testAnUnknownIdInTheBindingIsAConfigurationError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("'agent.noSuchPackage'");
        AISkill::resolvePackageIdsOfBinding(['Agent.Mandates' => ['agent.noSuchPackage']]);
    }

    public function testThePackagesDefaultBindingNamesItsPackagesByClass(): void
    {
        $defaultBinding = require dirname(__DIR__, 5) . '/config/app/AI/skillPackages.php';
        self::assertSame(
            [
                AISkill::AGENT_MANDATES => [AgentMandatesMCPPackage::class],
                AISkill::AGENT_TASKS => [AgentTaskSchedulesMCPPackage::class],
                AISkill::AGENT_TASK_RUN_REPORTS => [AgentTaskRunReportsMCPPackage::class],
                AISkill::AGENT_MEMORY => [AgentMemoriesMCPPackage::class],
                AISkill::AGENT_CONVERSATIONS => [AgentConversationsMCPPackage::class],
                AISkill::AGENT_GOALS => [AgentGoalsMCPPackage::class, AgentGoalBlueprintMCPPackage::class],
                AISkill::AGENT_IMAGES => [AgentImagesMCPPackage::class],
                AISkill::AGENT_WEB_SEARCH => [WebSearchMCPPackage::class],
                AISkill::AGENT_CONNECTIONS_OVERVIEW => [AgentConnectionsMCPPackage::class],
                AISkill::AGENT_CONNECTIONS_MAIL => [AgentConnectionsMailMCPPackage::class],
                AISkill::AGENT_CONNECTIONS_CALENDAR => [AgentConnectionsCalendarMCPPackage::class],
                AISkill::AGENT_CONNECTIONS_CONTACTS => [AgentConnectionsContactsMCPPackage::class],
                AISkill::AGENT_CONNECTIONS_FILES => [AgentConnectionsFilesMCPPackage::class],
                AISkill::AGENT_CONNECTIONS_DOCUMENTS => [AgentConnectionsDocumentsMCPPackage::class],
                AISkill::AGENT_CONNECTIONS_CHAT => [AgentConnectionsChatMCPPackage::class],
                AISkill::AGENT_UI => [AgentUIMCPPackage::class, AgentUIWindowMCPPackage::class, AgentUIActionsMCPPackage::class],
                AISkill::AGENT_VOICE => [AgentVoiceCallsMCPPackage::class],
                AISkill::SYSTEM_HEALTH => [SystemHealthMCPPackage::class],
                AISkill::SYSTEM_ISSUES => [SystemIssuesMCPPackage::class],
                AISkill::SYSTEM_WORKERS => [SystemWorkersMCPPackage::class],
                AISkill::SYSTEM_CRONS => [SystemCronsMCPPackage::class],
            ],
            AISkill::resolvePackageIdsOfBinding($defaultBinding)
        );
    }
}
