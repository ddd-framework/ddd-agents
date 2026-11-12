<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Embed one persisted `AIConversationMessage` asynchronously — the per-message leaf vector the
 * conversation-recall search ranks by. Dispatched after a TEXT message is persisted (so the embedding never blocks
 * the turn); the ABSENCE of an embedding row is the "not yet embedded" sentinel until this runs. Mirrors
 * {@see AIConversationCompactionMessage}.
 *
 * **Ids on the wire, nothing else:** only the message id; the handler re-`find()`s it. `accountId` + workspace are
 * captured by {@see AppMessage::__construct()} at DISPATCH time. `#[WithAIEnvelope]` opens the AI-usage envelope
 * (scope `ENVELOPE.AI.CONVERSATION.EMBEDDING`) around the handler — the Argus embedding op requires an active envelope.
 * The scope MUST be an `ENVELOPE.*` one ({@see WithAIEnvelope} fail-fast validates this at dispatch); the former
 * `AI_VECTOR_QUERY` (an operation scope, `AI.VECTOR.QUERY.EMBEDDINGS`) made every async dispatch throw — so async
 * embedding never actually ran until this was fixed.
 */
#[WithAIEnvelope(scope: AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_EMBEDDING)]
class AIConversationMessageEmbeddingMessage extends AppMessage
{
    public int $aiConversationMessageId;

    public static string $messageHandler = AIConversationMessageEmbeddingHandler::class;

    public function __construct(int $aiConversationMessageId)
    {
        parent::__construct();
        $this->aiConversationMessageId = $aiConversationMessageId;
    }
}
