<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Where a message stands: its conversation and its index there. Two numbers, no text — what a caller may learn of a
 * message before it knows whether it may read the message's conversation.
 */
class AIConversationMessagePosition extends ValueObject
{
    /** @var int The conversation of the message */
    public int $aiConversationId = 0;

    /** @var int The index of the message in its conversation */
    public int $indexInConversation = 0;

    public static function forConversationAndIndex(int $aiConversationId, int $indexInConversation): static
    {
        $messagePosition = new static();
        $messagePosition->aiConversationId = $aiConversationId;
        $messagePosition->indexInConversation = $indexInConversation;
        return $messagePosition;
    }
}
