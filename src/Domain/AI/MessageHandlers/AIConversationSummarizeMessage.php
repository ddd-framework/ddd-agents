<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Interfaces\AIConversationOverheadCostsMessageInterface;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * (Re)generate an `AIConversation`'s display TITLE + SUMMARY asynchronously — the async half of the
 * `bool $async` seam on {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::generateAndPersistSummary()}.
 * Dispatched after the FIRST user turn (so a brand-new conversation gets a title without blocking the reply) and after
 * each COMPACTION landing (so the title/summary track the whole conversation). The CLI/eval path summarizes INLINE
 * (sync), never via this message.
 *
 * **Ids on the wire, nothing else:** only the conversation id; the handler re-`find()`s it. `accountId` + workspace are
 * captured by {@see AppMessage::__construct()} at DISPATCH time. Routed to the existing `ai_conversation_compaction`
 * transport (same cadence/weight as compaction — no new worker/queue). `#[WithAIEnvelope]` opens the AI-usage envelope
 * around the handler — the Argus summarizer requires an active envelope.
 */
#[WithAIEnvelope(scope: AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_SUMMARIZE)]
class AIConversationSummarizeMessage extends AppMessage implements AIConversationOverheadCostsMessageInterface
{
    public int $aiConversationId;

    public static string $messageHandler = AIConversationSummarizeHandler::class;

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
