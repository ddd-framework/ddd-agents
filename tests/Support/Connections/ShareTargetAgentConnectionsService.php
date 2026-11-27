<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;

/** The host hooks of sharing, fixed for a test: the spaces an account may share with and the owner rule of the package */
class ShareTargetAgentConnectionsService extends AgentConnectionsService
{
    /** @var int[] */
    public array $shareableAgentSpaceIds = [];

    public function findShareableAgentSpaceIdsOfAccount(AgentConnection $agentConnection, int $accountId): array
    {
        return $this->shareableAgentSpaceIds;
    }
}
