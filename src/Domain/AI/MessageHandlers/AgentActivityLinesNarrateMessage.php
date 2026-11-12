<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Interfaces\AIConversationOverheadCostsMessageInterface;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Narrate the activity lines of the ROOT conversation's current turn — scheduled by the turn when one
 * of its steps changed while a reader (the web chat, a running call) is attached, consumed by the own pool
 * `ai_activity_lines`. Ids on the wire: only the root conversation id; the handler re-`find()`s it. The narrator call
 * needs an AI-usage envelope and is booked as overhead on the root conversation.
 */
#[WithAIEnvelope(scope: AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_ACTIVITY)]
class AgentActivityLinesNarrateMessage extends AppMessage implements AIConversationOverheadCostsMessageInterface
{
    public int $rootConversationId;

    public static string $messageHandler = AgentActivityLinesNarrateHandler::class;

    public function __construct(int $rootConversationId)
    {
        parent::__construct();
        $this->rootConversationId = $rootConversationId;
    }

    public function getAIConversationIdForOverheadCosts(): ?int
    {
        return $this->rootConversationId;
    }
}
