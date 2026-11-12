<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Write the SCORECARD of one finalized root conversation and run its quality analysis — dispatched by
 * {@see AIConversationFinalizeHandler} AFTER the completion compaction, so nothing waits for the analysis and the
 * compaction tree already reaches the last message (a long conversation is read through it). Ids only; the handler
 * loads fresh. Idempotent: the metrics write is guarded by the covered message index, the analysis skips a
 * conversation whose analysis already covers its newest message.
 *
 * `#[WithAIEnvelope]` opens the AI-usage envelope the analysis call requires (the finalize chain's scope).
 */
#[WithAIEnvelope(scope: AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_COMPACTION)]
class AIConversationScorecardMessage extends AppMessage
{
    public int $aiConversationId;

    /** @var bool Analyze again even when the analysis covers the newest message (a backfill after a prompt change). */
    public bool $forceAnalysis = false;

    public static string $messageHandler = AIConversationScorecardHandler::class;

    public function __construct(int $aiConversationId, bool $forceAnalysis = false)
    {
        parent::__construct();
        $this->aiConversationId = $aiConversationId;
        $this->forceAnalysis = $forceAnalysis;
    }
}
