<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Listeners\Conversations;

use DDD\Domain\AI\Services\Conversations\AIConversationSettledOwnerTurnListenersService;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What another domain does after a SETTLED owner turn: the loop answered the owner,
 * released the run and re-fired nothing — the conversation's owner lines are screened and answered. The loop calls every
 * implementation through {@see AIConversationSettledOwnerTurnListenersService} and imports none of them; an
 * implementation is a service of its own domain, registered by this tag (autoconfigured). Best-effort: a listener that
 * throws is skipped — the owner's answer is persisted already. A purpose hook cannot do this work: the owner's chat has
 * no purpose.
 */
#[AutoconfigureTag(AIConversationSettledOwnerTurnListener::TAG)]
interface AIConversationSettledOwnerTurnListener
{
    public const string TAG = 'ai.conversation.settled_owner_turn_listener';

    /** React to the settled owner turn of `$conversation` (the loop's worker path, after the release). */
    public function afterSettledOwnerTurn(AIConversation $conversation): void;
}
