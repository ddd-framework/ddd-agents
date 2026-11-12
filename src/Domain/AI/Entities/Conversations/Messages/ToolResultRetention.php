<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * What rides the wire of the next model call, per tool RESULT (budget by fit): a
 * result on a message index BELOW {@see $fromIndex} is stubbed (older than the kept turn window, or below the
 * oldest result the token budget still holds); a result at or above it rides verbatim UNLESS its index is in
 * {@see $stubbedIndexes} — a SEEN result the budget could not fit while a smaller, older one did. `PHP_INT_MIN`
 * with an empty set = keep all. Computed ONCE over the bounded tail by
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::resolveToolResultRetention()}.
 */
class ToolResultRetention
{
    /**
     * @param array<int, true> $stubbedIndexes message indexes (at or above $fromIndex) whose results are stubbed by the budget
     */
    public function __construct(
        public int $fromIndex = PHP_INT_MIN,
        public array $stubbedIndexes = []
    ) {
    }

    /** Whether the result on the given message index rides verbatim. */
    public function keeps(int $messageIndex): bool
    {
        return $messageIndex >= $this->fromIndex && !isset($this->stubbedIndexes[$messageIndex]);
    }
}
