<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The model-counted token cost of each message of a conversation, by `indexInConversation` (formerly
 * `int[]` threaded through the leaf planners). Built by
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildMessageFootprints()}; a message without an entry
 * costs 0.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMessageFootprints extends ValueObject
{
    /** @var int[] indexInConversation → footprint tokens */
    protected array $tokensByIndex = [];

    /** @param int[] $tokensByIndex */
    public static function fromTokensByIndex(array $tokensByIndex): static
    {
        $messageFootprints = new static();
        $messageFootprints->tokensByIndex = $tokensByIndex;
        return $messageFootprints;
    }

    public function getTokensAtIndex(int $messageIndex): int
    {
        return $this->tokensByIndex[$messageIndex] ?? 0;
    }

    /**
     * The summed footprint of $messages (a message without an index counts at index -1).
     *
     * @param AIConversationMessage[] $messages
     */
    public function sumOverMessages(array $messages): int
    {
        $tokens = 0;
        foreach ($messages as $message) {
            $tokens += $this->getTokensAtIndex($message->indexInConversation ?? -1);
        }
        return $tokens;
    }

    /** @return int[] */
    public function toTokensByIndex(): array
    {
        return $this->tokensByIndex;
    }
}
