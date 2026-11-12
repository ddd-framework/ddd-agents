<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Interfaces\AIConversationOverheadCostsMessageInterface;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Run the COMPLETION compaction for ONE finalized conversation (idle finalize) — dispatched by
 * a console command of the application for every conversation whose `finalizedAt` is newer than its
 * `finalizeCompactedAt`. Ids only; the handler loads fresh and runs
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationCompactionService::finalizeConversationCompaction()}
 * (completion tree → final summary → stamp). Idempotent: a redelivered/double-dispatched job finds the tree already
 * covering to the end (lands nothing) and re-stamps.
 *
 * `#[WithAIEnvelope]` opens the AI-usage envelope around the handler — the Argus completion summarizer + the final
 * display-summary refresh require an active envelope (same scope as the threshold compaction; without it the first
 * live run threw MissingAIEnvelopeException).
 */
#[WithAIEnvelope(scope: AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_COMPACTION)]
class AIConversationFinalizeMessage extends AppMessage implements AIConversationOverheadCostsMessageInterface
{
    public int $aiConversationId;

    public static string $messageHandler = AIConversationFinalizeHandler::class;

    public function __construct(int $aiConversationId)
    {
        parent::__construct();
        $this->aiConversationId = $aiConversationId;
    }

    public function getAIConversationIdForOverheadCosts(): ?int
    {
        return $this->aiConversationId;
    }
}
