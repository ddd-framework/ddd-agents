<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The three columns that make a conversation a delegation child, as stored: the anchoring
 * `delegate` call, the batch it settles into, and the tree root. Read raw by
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::findDelegationAnchorById()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentDelegationAnchor extends ValueObject
{
    public ?int $delegatedFromMessageId = null;

    public ?int $delegationBatchId = null;

    public ?int $rootConversationId = null;

    /**
     * The anchor of one raw conversations row (`delegatedFromMessageId`, `delegationBatchId`, `rootConversationId`).
     *
     * @param array<string, mixed> $conversationRow
     */
    public static function fromRow(array $conversationRow): static
    {
        $agentDelegationAnchor = new static();
        $agentDelegationAnchor->delegatedFromMessageId = ($conversationRow['delegatedFromMessageId'] ?? null) !== null ? (int)$conversationRow['delegatedFromMessageId'] : null;
        $agentDelegationAnchor->delegationBatchId = $conversationRow['delegationBatchId'] !== null ? (int)$conversationRow['delegationBatchId'] : null;
        $agentDelegationAnchor->rootConversationId = $conversationRow['rootConversationId'] !== null ? (int)$conversationRow['rootConversationId'] : null;
        return $agentDelegationAnchor;
    }
}
