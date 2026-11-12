<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Listeners\Conversations\AIConversationSettledOwnerTurnListener;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The listeners of a settled owner turn ({@see AIConversationSettledOwnerTurnListener}), handed in by the container —
 * every service carrying the tag (the `#[Required]` setter below, autowired with the tagged iterator) — and called in
 * registration order by the loop's settled-turn follow-ups. An application implements the interface to react to a
 * settled owner turn (e.g. an intake of owner input for a long-running build).
 */
class AIConversationSettledOwnerTurnListenersService extends Service
{
    /** @var iterable<AIConversationSettledOwnerTurnListener> the tagged services, resolved lazily on each iteration */
    protected iterable $listeners = [];

    /** @param iterable<AIConversationSettledOwnerTurnListener> $listeners */
    #[Required]
    public function setListeners(
        #[AutowireIterator(AIConversationSettledOwnerTurnListener::TAG)]
        iterable $listeners
    ): void
    {
        $this->listeners = $listeners;
    }

    /**
     * Every listener reacts to the settled owner turn. Best-effort per listener: a throw is swallowed — the turn already
     * produced and persisted its answer, and the listener's own recovery (a cron pass of its own) delivers later.
     */
    public function notifyListenersOfSettledOwnerTurn(AIConversation $conversation): void
    {
        foreach ($this->listeners as $settledOwnerTurnListener) {
            try {
                $settledOwnerTurnListener->afterSettledOwnerTurn($conversation);
            } catch (Throwable) {
                // swallow — the chat's answer is persisted; the listener's own recovery delivers later
            }
        }
    }
}
