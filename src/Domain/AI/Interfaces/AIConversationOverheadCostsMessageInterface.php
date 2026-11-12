<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Interfaces;

/**
 * A Messenger message whose work runs FOR one conversation (its compaction, summary, finalize, memory extraction,
 * tool-result screening, its turn). While such a message is consumed, the AI costs booked outside the
 * conversation's message rows and tool calls are recorded and added to that conversation
 * (the host's overhead-costs Messenger middleware,
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::addOverheadCostsToConversation()}).
 */
interface AIConversationOverheadCostsMessageInterface
{
    /** The conversation the work runs for; null = nothing to attribute (e.g. a message serialized before the field existed). */
    public function getAIConversationIdForOverheadCosts(): ?int;
}
