<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;

/** Records which read of the recent sessions the service takes, with its arguments, instead of reading. */
class AIConversationsServiceRecordingRecentSessionReads extends AIConversationsService
{
    /** @var array<int, array<string, int|null>> */
    public array $readsOfOneSpace = [];

    /** @var array<int, array<string, int|null>> */
    public array $readsAcrossTheSpacesOfAnAccount = [];

    protected function findRecentConversationsWithSummariesInAgentSpacePartition(
        int $accountId,
        AgentSpaceSqlCondition $agentSpaceSqlCondition,
        ?int $excludeConversationId = null,
        int $limit = 25
    ): AIConversations {
        $this->readsOfOneSpace[] = [
            'accountId' => $accountId,
            'agentSpaceId' => $agentSpaceSqlCondition->agentSpaceId,
            'excludeConversationId' => $excludeConversationId,
        ];
        return new AIConversations();
    }

    public function findRecentConversationsWithSummariesOfAccountIdAcrossAgentSpaces(
        int $accountId,
        ?int $excludeConversationId = null,
        int $limit = 25
    ): AIConversations {
        $this->readsAcrossTheSpacesOfAnAccount[] = ['accountId' => $accountId, 'excludeConversationId' => $excludeConversationId];
        return new AIConversations();
    }
}
