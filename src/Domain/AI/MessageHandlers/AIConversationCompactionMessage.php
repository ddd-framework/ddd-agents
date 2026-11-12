<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Interfaces\AIConversationOverheadCostsMessageInterface;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Compact an `AIConversation`'s history asynchronously — the async half of the `bool $async` seam, mirroring
 * {@see AIConversationResumeMessage}. Used by the HTTP/admin path; the CLI/eval run compaction INLINE (sync), never via
 * this message.
 *
 * **Ids on the wire, nothing else:** only the conversation id; the handler re-`find()`s it and derives the anchor at
 * run time. `accountId` + workspace are captured by {@see AppMessage::__construct()} at DISPATCH time.
 * `#[WithAIEnvelope]` opens the AI-usage envelope around the handler — the Argus summarizer requires an active envelope
 * (the sync CLI path already has the turn's envelope active).
 */
#[WithAIEnvelope(scope: AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_COMPACTION)]
class AIConversationCompactionMessage extends AppMessage implements AIConversationOverheadCostsMessageInterface
{
    public int $aiConversationId;

    public static string $messageHandler = AIConversationCompactionHandler::class;

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
