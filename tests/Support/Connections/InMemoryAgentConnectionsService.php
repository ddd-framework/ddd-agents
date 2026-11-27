<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermissions;
use DDD\Domain\AI\Entities\Connections\AgentConnectionReleases;
use DDD\Domain\AI\Services\Connections\Adapters\OAuthAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;

/** The connections service on in-memory releases, permissions, memberships and one OAuth adapter (no database) */
class InMemoryAgentConnectionsService extends AgentConnectionsService
{
    public AgentConnectionReleases $releases;

    public AgentConnectionPermissions $permissions;

    /** @var array<int, int[]> account id => the spaces it is a member of */
    public array $memberAgentSpaceIdsByAccountId = [];

    public ?OAuthAgentConnectionAdapter $oAuthAdapter = null;

    /** @var AgentConnection[] by id */
    public array $connectionsById = [];

    /** @var int[] the conversations others read (every other conversation is read by its owner alone) */
    public array $conversationIdsReadByOthers = [];

    public function __construct()
    {
        $this->releases = new AgentConnectionReleases();
        $this->permissions = new AgentConnectionPermissions();
    }

    public function findIgnoringRights(int $agentConnectionId): ?AgentConnection
    {
        return $this->connectionsById[$agentConnectionId] ?? null;
    }

    public function findActiveReleasesOfConnection(AgentConnection $agentConnection): AgentConnectionReleases
    {
        return $this->releases;
    }

    public function findPermissionsOfConnection(AgentConnection $agentConnection): AgentConnectionPermissions
    {
        return $this->permissions;
    }

    public function isConversationReadByOthers(int $aIConversationId, AgentConnection $agentConnection): bool
    {
        return in_array($aIConversationId, $this->conversationIdsReadByOthers, true);
    }

    public function isSharedChatThread(int $aIConversationId): bool
    {
        return false;
    }

    public function isAccountMemberOfAgentSpace(int $accountId, int $agentSpaceId): bool
    {
        return in_array($agentSpaceId, $this->memberAgentSpaceIdsByAccountId[$accountId] ?? [], true);
    }

    protected function findOAuthAdapterOfConnection(AgentConnection $agentConnection): ?OAuthAgentConnectionAdapter
    {
        return $this->oAuthAdapter;
    }

    protected function invalidateReadCache(AgentConnection $agentConnection): void
    {
    }
}
