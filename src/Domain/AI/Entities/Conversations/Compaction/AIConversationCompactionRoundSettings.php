<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one threshold round of the compaction tree works with: the token budget
 * (or none on a degenerate budget, where the message-count fallback plans the leaves), the per-message footprints the
 * leaves are sized and stamped by, the level-0 ranges the round closes, how many exposed nodes one roll-up
 * consolidates and how many of the newest exposed nodes of each level stay unrolled. Built by
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildCompactionRoundSettings()} from the loaded messages,
 * the loaded tree and the conversation's {@see \DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings};
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationCompactionService} runs the round on it. Transient, never
 * persisted.
 */
class AIConversationCompactionRoundSettings extends ValueObject
{
    /** @var CompactionBudgets|null The round's token budget; null when the budget is too small to tile (the message-count fallback runs). */
    public ?CompactionBudgets $compactionBudgets = null;

    /** @var AIConversationMessageFootprints|null The per-message footprints; null on the fallback (the round builds them at its first leaf). */
    public ?AIConversationMessageFootprints $messageFootprints = null;

    /** @var AIConversationCompactionLeafRanges The level-0 ranges the round closes, oldest first. */
    public AIConversationCompactionLeafRanges $leafRanges;

    /** @var int How many exposed nodes of one level one roll-up consolidates into one node one level up. */
    public int $childNodesPerRollUp = CompactionBudgets::ROLLUP_FAN_IN;

    /** @var int How many of the newest exposed nodes of each level stay unrolled. */
    public int $retainedExposedNodesPerLevel = CompactionBudgets::FINE_NODES_PER_LEVEL;
}
