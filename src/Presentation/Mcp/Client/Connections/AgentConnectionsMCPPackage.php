<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Services\Connections\AgentConnectionReach;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionsListMcpResponseDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionsListRequestDto;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionSummaryDto;

/**
 * The overview of the connections (skill `Agent.Connections.Overview`, package id `agent.connections.overview`): which external
 * accounts are connected and usable here, of every provider and both modes, under which name, at which level per
 * capability. The account may hold any number of them.
 */
#[MCPRequiresAccountRights(AgentConnectionsAccountRights::USE_AGENT_CONNECTIONS)]
class AgentConnectionsMCPPackage extends AgentConnectionsBaseMCPPackage
{
    public static ?string $name = 'agent.connections.overview';

    public static ?string $description = 'The connected external accounts usable here (mailboxes, calendars, contacts, drives, chat): name, provider, identity, levels per capability, status.';

    /**
     * Every connection usable in this conversation: its name (use it as `connection` in the other tools), provider,
     * external identity, mode (the account's own or a system connection of the agent), visibility, status and per
     * capability the level (OFF, READ, DRAFT, WRITE_WITH_APPROVAL, DELETE_WITH_APPROVAL, SEND_WITH_APPROVAL, SEND_BY_RULE). Ask this first when the owner names a mailbox,
     * calendar or drive, or when a tool says several connections exist. Connecting is done by a person in the
     * connection settings, never by a tool.
     *
     * @example {}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('reads the connection list')]
    #[MCPToolSummary('The connections usable here (name, provider, identity, levels per capability, status). Use the name as `connection` in the mail, calendar, contacts, files and chat tools.')]
    public function listConnections(AgentConnectionsListRequestDto $requestDto, AgentToolCallProvenance $callProvenance): AgentConnectionsListMcpResponseDto
    {
        $agentConnectionsService = $this->getAgentConnectionsService();
        $accountId = $this->findActingAccountId($callProvenance);
        $responseDto = new AgentConnectionsListMcpResponseDto();
        foreach ($this->findUsableConnections($callProvenance)->getElements() as $agentConnection) {
            $responseDto->connections[] = static::summarize($agentConnection, $agentConnectionsService, $accountId, AgentConnectionReach::ofCall($callProvenance));
        }
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        if (!$agentConnectionsConfiguration->isProductionStage()) {
            $allowedRecipients = $agentConnectionsConfiguration->getNonProductionAllowedRecipients();
            $responseDto->stageRecipientNote = 'Stage ' . $agentConnectionsConfiguration->getCurrentStage() . ': only '
                . ($allowedRecipients === [] ? 'nobody' : implode(', ', $allowedRecipients))
                . ' receive what is sent through a real connection; any other recipient is refused there even with the level. A FAKE connection (provider FAKE) takes any address. Drafts are never limited: show the mail anyway, name the refused recipients in the same message and leave them out.';
        }
        return $responseDto;
    }

    public static function summarize(AgentConnection $agentConnection, AgentConnectionsService $agentConnectionsService, int $accountId, AgentConnectionReach $agentConnectionReach): AgentConnectionSummaryDto
    {
        $summaryDto = new AgentConnectionSummaryDto();
        $summaryDto->connectionId = (int)$agentConnection->id;
        $summaryDto->name = $agentConnection->getLabel();
        $summaryDto->provider = (string)$agentConnection->provider;
        $summaryDto->externalAddress = $agentConnection->externalAddress;
        $summaryDto->ownership = $agentConnection->ownership;
        $summaryDto->ownerAccountId = $agentConnection->accountId;
        $summaryDto->connectedByAccountId = $agentConnection->connectedByAccountId;
        $summaryDto->visibility = $agentConnectionsService->getVisibility($agentConnection);
        $summaryDto->status = $agentConnection->status;
        $levelParts = [];
        foreach ($agentConnectionsService->findPermissionsOfConnection($agentConnection)->getElements() as $agentConnectionPermission) {
            $level = $agentConnectionsService->getEffectiveLevel($agentConnection, (string)$agentConnectionPermission->capability, $accountId, $agentConnectionReach);
            $levelParts[] = $agentConnectionPermission->capability . '=' . $level . ' (' . AgentConnectionPermission::labelOfLevel((string)$agentConnectionPermission->capability, $level) . ')';
        }
        $summaryDto->levels = implode('; ', $levelParts);
        $summaryDto->scopeBoundaries = $agentConnection->scopeBoundaries ?? null;
        $summaryDto->isDefault = $agentConnection->isDefaultForItsCapabilities;
        $summaryDto->lastErrorText = $agentConnection->lastErrorText;
        $summaryDto->lastUsedAt = $agentConnection->lastUsedAt;
        $summaryDto->connectedAt = $agentConnection->changeHistory->createdTime ?? null;
        return $summaryDto;
    }
}
