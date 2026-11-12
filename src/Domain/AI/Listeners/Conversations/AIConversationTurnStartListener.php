<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Listeners\Conversations;

use DDD\Domain\AI\Services\Conversations\AIConversationTurnStartListenersService;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What another part of the platform writes into a conversation right before its turn calls the model — a fact that
 * became true while nobody looked (the in-app ring of a call on this conversation passed its deadline unanswered) and
 * that the model must read in this very turn. The loop calls every implementation through
 * {@see AIConversationTurnStartListenersService} and imports none of them; an implementation is a service of its own
 * area, registered by this tag (autoconfigured), mirroring {@see AIConversationSettledOwnerTurnListener}.
 *
 * Contract: cheap when there is nothing to write (one indexed read), idempotent, best-effort (a throw is swallowed —
 * the turn runs without the row), and it answers whether it PERSISTED rows into the conversation, so the loop reloads
 * the conversation's rows before the first model call.
 */
#[AutoconfigureTag(AIConversationTurnStartListener::TAG)]
interface AIConversationTurnStartListener
{
    public const string TAG = 'ai.conversation.turn_start_listener';

    /**
     * Persist what `$conversation` must carry before its turn's first model call; true when a row was written into it.
     */
    public function beforeTurnStarts(AIConversation $conversation): bool;
}
