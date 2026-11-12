<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Interfaces\AIConversationOverheadCostsMessageInterface;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Screen ONE untrusted tool result for injections — dispatched by the turn right after the call
 * completed, consumed by the own pool `ai_tool_result_screening` (about a quarter of the turn workers). Ids on the
 * wire: only the call message id; the handler re-`find()`s it. The decision calls need an AI-usage envelope; the
 * work belongs to the turn that read the result.
 */
#[WithAIEnvelope(scope: AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN)]
class AgentToolResultScreeningMessage extends AppMessage implements AIConversationOverheadCostsMessageInterface
{
    public int $aiConversationMessageId;

    /** @var int|null The call message's conversation — the screening's cost is booked on it; null in a message queued before the field. */
    public ?int $aiConversationId = null;

    public static string $messageHandler = AgentToolResultScreeningHandler::class;

    public function __construct(int $aiConversationMessageId, ?int $aiConversationId = null)
    {
        parent::__construct();
        $this->aiConversationMessageId = $aiConversationMessageId;
        $this->aiConversationId = $aiConversationId;
    }

    public function getAIConversationIdForOverheadCosts(): ?int
    {
        return $this->aiConversationId;
    }
}
