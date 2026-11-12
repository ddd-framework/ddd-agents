<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The admin-debug breakdown of WHAT the compaction layer contributed to ONE message's model context, reconstructed
 * point-in-time (as the tree stood when that message was produced — {@see \DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot::$sentAt}).
 * Assembled by {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildMessageCompactionContext()} and rendered
 * by the admin inspector as the per-message "which aspects went into this context" tree. Pure data carrier — no repo.
 */
class AIConversationMessageCompactionContext extends ValueObject
{
    /** @var AIConversationCompactionNodes The cover nodes (coarsest-old → finest-recent) whose summaries formed the synthetic "conversation so far" block for this message — the compacted aspects of its context. */
    public AIConversationCompactionNodes $coverNodes;

    /** @var int|null The prefix-tiling edge: the highest message index the cover fully covered for this message. Null = nothing was compacted yet (the whole prior history was raw). */
    public ?int $coveredUpToMessageIndex = null;

    /** @var int[] indexInConversation of the RAW verbatim predecessor messages that were also in this message's context (index > coveredUpToMessageIndex and before this message) — the un-compacted aspects. */
    public array $rawTailMessageIndices = [];

    /** @var string|null The rendered "conversation so far" summary text the cover produced for this message (standing constraints + preserved identifiers + per-range blocks). Null when nothing was compacted. */
    public ?string $compactionSummaryText = null;

    /** @var DateTime|null The point-in-time the reconstruction used (this message's requestSnapshot.sentAt). Null = reconstructed against the current tree (no snapshot on the message). */
    public ?DateTime $asOf = null;
}
