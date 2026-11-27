<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest;
use DDD\Domain\AI\Services\Connections\AgentConnectionApprovalRequestsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;

/**
 * The answer path of approvals without a database: the conditional claim is a compare-and-set on the request's status
 * that a parallel answer can win first.
 */
class ClaimTestApprovalRequestsService extends AgentConnectionApprovalRequestsService
{
    /** @var string[] the statuses claims tried to set */
    public array $claimedStatuses = [];

    /** True: another answer wins the row between the checks and the claim */
    public bool $aParallelAnswerWinsTheRow = false;

    public InMemoryAgentConnectionsService $inMemoryConnectionsService;

    public function __construct()
    {
        $this->inMemoryConnectionsService = new InMemoryAgentConnectionsService();
    }

    public function claimOpenRequest(AgentConnectionApprovalRequest $agentConnectionApprovalRequest, string $newStatus, ?int $answeringAccountId, ?string $answeredVia, ?int $answeredThroughAgentConnectionId): bool
    {
        $this->claimedStatuses[] = $newStatus;
        if ($this->aParallelAnswerWinsTheRow || $agentConnectionApprovalRequest->status !== AgentConnectionApprovalRequest::STATUS_OPEN) {
            return false;
        }
        $agentConnectionApprovalRequest->status = $newStatus;
        return true;
    }

    public function mayApprove(AgentConnectionApprovalRequest $agentConnectionApprovalRequest, AgentConnection $agentConnection, int $answeringAccountId): bool
    {
        return true;
    }

    protected function getAgentConnectionsService(): AgentConnectionsService
    {
        return $this->inMemoryConnectionsService;
    }
}
