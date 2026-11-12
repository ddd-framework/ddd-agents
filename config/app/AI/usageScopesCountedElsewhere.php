<?php

declare(strict_types=1);

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;

/**
 * The usage scopes whose booked costs stay out of the booked-cost recordings of
 * {@see \DDD\Domain\AI\Services\AIUsageEnvelopesService} (read as `Config::get('AI.usageScopesCountedElsewhere')`):
 * the chat model's call of a conversation turn — the conversation stores that cost on its own message row, so a tool
 * call's or a turn's recording counting it again would count it twice. An application's file at the same path
 * replaces this one whole.
 */
return [
    AgentAIUsageScope::AI_CONVERSATION_SEND_MESSAGE,
];
