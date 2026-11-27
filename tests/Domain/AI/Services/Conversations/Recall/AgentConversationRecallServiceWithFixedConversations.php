<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Recall;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Recall\AgentConversationRecallTranscript;
use DDD\Domain\AI\Services\Conversations\AgentConversationRecallService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The recall service over a fixed list of conversations and messages: no database, no container. The audience answer
 * is a list of conversation ids others read. With `$recordsReadsInsteadOfReading` a read records its arguments instead
 * of reading the message rows.
 */
class AgentConversationRecallServiceWithFixedConversations extends AgentConversationRecallService
{
    public ?AIConversations $candidateConversations = null;

    /** @var array<int, AIConversationMessage> The messages a read around a message id finds, by id */
    public array $messagesById = [];

    public bool $recordsReadsInsteadOfReading = false;

    /** @var array<int, int|null>|null conversation id, from index, limit, around index of the last recorded read */
    public ?array $recordedRead = null;

    public function readConversation(
        ?AIConversation $currentConversation,
        int $conversationId,
        int $fromIndex,
        int $limit,
        ?int $aroundIndex = null
    ): AgentConversationRecallTranscript {
        if (!$this->recordsReadsInsteadOfReading) {
            return parent::readConversation($currentConversation, $conversationId, $fromIndex, $limit, $aroundIndex);
        }
        $this->recordedRead = [$conversationId, $fromIndex, $limit, $aroundIndex];
        return new AgentConversationRecallTranscript();
    }

    protected function findMessageToReadAround(int $messageId): ?AIConversationMessage
    {
        return $this->messagesById[$messageId] ?? null;
    }

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
