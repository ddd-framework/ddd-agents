<?php

declare(strict_types=1);

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalBlueprintMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentImagesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentMandatesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentMemoriesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskRunReportsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentUIActionsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentUIMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentUIWindowMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\WebSearchMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsMailMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsCalendarMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsContactsMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsDocumentsMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsFilesMCPPackage;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsChatMCPPackage;
use DDD\Presentation\Mcp\Client\Voice\AgentVoiceCallsMCPPackage;
use DDD\Presentation\Mcp\Admin\System\SystemCronsMCPPackage;
use DDD\Presentation\Mcp\Admin\System\SystemHealthMCPPackage;
use DDD\Presentation\Mcp\Admin\System\SystemIssuesMCPPackage;
use DDD\Presentation\Mcp\Admin\System\SystemWorkersMCPPackage;

/**
 * The package's default skill → MCP-package binding, read as `Config::get('AI.skillPackages')` by
 * {@see AISkill::getPackageClassesBySkillName()}. It lists only the skills whose MCP packages ship with this package,
 * bound by class; their skill files ship in `config/app/AI/Skills/Agent/` and `config/app/AI/Skills/System/`.
 * A skill of a feature that is switched off stays bound here and is not offered: its package is not available.
 *
 * An application ships its own `config/app/AI/skillPackages.php`, which REPLACES this file whole (application config
 * roots are searched first): it must list these skills itself when it wants them offered.
 */
return [
    AISkill::AGENT_MANDATES => [AgentMandatesMCPPackage::class],
    AISkill::AGENT_TASKS => [AgentTaskSchedulesMCPPackage::class],
    AISkill::AGENT_TASK_RUN_REPORTS => [AgentTaskRunReportsMCPPackage::class],
    // offered only while the memory store is switched on (AI.agentMemory.enabled): the package is unavailable otherwise
    AISkill::AGENT_MEMORY => [AgentMemoriesMCPPackage::class],
    // offered only while the goal register is switched on (AI.agentGoals.enabled); the standard goal tree's package only
    // while an application binds a blueprint provider (AgentGoalBlueprintMCPPackage::isAvailable())
    AISkill::AGENT_GOALS => [AgentGoalsMCPPackage::class, AgentGoalBlueprintMCPPackage::class],
    // offered only while the picture store keeps pictures and their retention is on (AgentImagesMCPPackage::isAvailable())
    AISkill::AGENT_IMAGES => [AgentImagesMCPPackage::class],
    // offered only while the web research is switched on (AI.webSearch) and its capability has an endpoint
    AISkill::AGENT_WEB_SEARCH => [WebSearchMCPPackage::class],
    // AgentConnections (switched off unless AI.agentConnections.enabled; each package hides itself otherwise)
    AISkill::AGENT_CONNECTIONS_OVERVIEW => [AgentConnectionsMCPPackage::class],
    AISkill::AGENT_CONNECTIONS_MAIL => [AgentConnectionsMailMCPPackage::class],
    AISkill::AGENT_CONNECTIONS_CALENDAR => [AgentConnectionsCalendarMCPPackage::class],
    AISkill::AGENT_CONNECTIONS_CONTACTS => [AgentConnectionsContactsMCPPackage::class],
    AISkill::AGENT_CONNECTIONS_FILES => [AgentConnectionsFilesMCPPackage::class],
    AISkill::AGENT_CONNECTIONS_DOCUMENTS => [AgentConnectionsDocumentsMCPPackage::class],
    AISkill::AGENT_CONNECTIONS_CHAT => [AgentConnectionsChatMCPPackage::class],
    // the user's client: requests over the realtime channel (AI.agentRealtimeChannel); the window-mode package only
    // while AI.agentUI.windowModeRequests is on (AgentUIWindowMCPPackage::isAvailable())
    AISkill::AGENT_UI => [AgentUIMCPPackage::class, AgentUIWindowMCPPackage::class, AgentUIActionsMCPPackage::class],
    // offered only while the voice channel can run: AI.agentVoice.enabled, and the session worker's WebSocket library
    // is installed (AgentVoiceCallsMCPPackage::isAvailable())
    AISkill::AGENT_VOICE => [AgentVoiceCallsMCPPackage::class],
    // Core's features for the platform operators: offered and callable only with the right operatePlatform
    // (AI.accountRights maps it to the superadmins) and an account with access to every resource
    AISkill::SYSTEM_HEALTH => [SystemHealthMCPPackage::class],
    AISkill::SYSTEM_ISSUES => [SystemIssuesMCPPackage::class],
    AISkill::SYSTEM_WORKERS => [SystemWorkersMCPPackage::class],
    AISkill::SYSTEM_CRONS => [SystemCronsMCPPackage::class],
];
