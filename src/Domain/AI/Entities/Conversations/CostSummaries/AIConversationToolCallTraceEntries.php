<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\CostSummaries;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The ordered tool-call trace of a conversation tree — root plus delegation children, each conversation's
 * calls in append order. Pure derivations fold over the loaded entries.
 *
 * @property AIConversationToolCallTraceEntry[] $elements
 * @method AIConversationToolCallTraceEntry[] getElements()
 */
class AIConversationToolCallTraceEntries extends ObjectSet
{
    /** @return AIConversationToolCallTraceEntry[] Entries of one conversation, in append order. */
    public function getForConversationId(int $conversationId): array
    {
        return array_values(array_filter(
            $this->getElements(),
            static fn (AIConversationToolCallTraceEntry $entry): bool => $entry->conversationId === $conversationId
        ));
    }
}
