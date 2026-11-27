<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Recall;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Services\Conversations\AgentConversationRecallService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The recall service over a fixed list of conversations: no database, no container. The audience answer is a list of
 * conversation ids others read.
 */
class AgentConversationRecallServiceWithFixedConversations extends AgentConversationRecallService
{
    public ?AIConversations $candidateConversations = null;

    /** @var int[] Ids of the conversations others read */
    public array $conversationIdsReadByOthers = [];

    /** @var string[] The words the last candidate search received */
    public array $searchedWords = [];

    protected function findRecallCandidates(Account $account, array $words, ?int $agentSpaceId, ?DateTime $from, ?DateTime $to, int $limit): ?AIConversations
    {
        $this->searchedWords = $words;
        return $this->candidateConversations;
    }

    protected function findReadableConversation(int $conversationId): ?AIConversation
    {
        foreach ($this->candidateConversations?->getElements() ?? [] as $conversation) {
            if ((int)$conversation->id === $conversationId) {
                return $conversation;
            }
        }
        return null;
    }

    protected function findOwnConversation(int $conversationId): ?AIConversation
    {
        return $this->findReadableConversation($conversationId);
    }

    protected function requireRecallEnabled(?AIConversation $currentConversation): void
    {
    }

    public function isConversationSharedWithOthers(AIConversation $conversation): bool
    {
        return in_array((int)$conversation->id, $this->conversationIdsReadByOthers, true);
    }

    public function describeAgentSpace(?int $agentSpaceId): string
    {
        return $agentSpaceId === null ? 'account level' : 'space ' . $agentSpaceId;
    }

    /** @return string[] */
    public function splitQueryIntoWords(string $query): array
    {
        return $this->splitIntoWords($query);
    }
}
