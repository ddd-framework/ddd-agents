<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionSummary;
use DDD\Domain\AI\Services\Conversations\AIConversationCompactionService;

/**
 * The compaction service with its two external calls replaced: the summarizer answers a fixed gist (no Argus call) and
 * the node embedding is skipped. Everything else — leaf tiling, the roll-up cascade, the in-memory node set of an
 * ephemeral conversation — runs as in production.
 */
class FixedSummaryCompactionServiceDouble extends AIConversationCompactionService
{
    /** How often the summarizer was asked (leaves + roll-ups). */
    public int $summarizerCallCount = 0;

    protected function summarizeForCompaction(AIConversation $conversation, string $compactionInput): AIConversationCompactionSummary
    {
        $this->summarizerCallCount++;
        return AIConversationCompactionSummary::fromSummarizerOutput('gist ' . $this->summarizerCallCount, 'TEST.SUMMARIZER', 10);
    }

    protected function embedCompactionNode(AIConversationCompactionNode $compactionNode): void
    {
    }
}
