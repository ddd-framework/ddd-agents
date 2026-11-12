<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Listeners\Conversations\AIConversationTurnStartListener;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The listeners of a turn start ({@see AIConversationTurnStartListener}), handed in by the container — every service
 * carrying the tag (the `#[Required]` setter below, autowired with the tagged iterator) — and called in registration
 * order by {@see AIConversationAgentService::generateAgentReply()} before the turn's first model call. An application
 * implements the interface to write a row the turn must read (e.g. the expiry of an unanswered in-app ring).
 */
class AIConversationTurnStartListenersService extends Service
{
    /** @var iterable<AIConversationTurnStartListener> the tagged services, resolved lazily on each iteration */
    protected iterable $listeners = [];

    /** @param iterable<AIConversationTurnStartListener> $listeners */
    #[Required]
    public function setListeners(
        #[AutowireIterator(AIConversationTurnStartListener::TAG)]
        iterable $listeners
    ): void
    {
        $this->listeners = $listeners;
    }

    /**
     * Every listener writes what the turn must read; returns true when any of them persisted a row into the
     * conversation. Best-effort per listener: a throw is logged and skipped — the turn runs without that row.
     */
    public function notifyListenersOfTurnStart(AIConversation $conversation): bool
    {
        $anyRowPersisted = false;
        foreach ($this->listeners as $turnStartListener) {
            try {
                $anyRowPersisted = $turnStartListener->beforeTurnStarts($conversation) || $anyRowPersisted;
            } catch (Throwable $listenerError) {
                DDDService::instance()->getLogger()->warning(
                    'AIConversationTurnStartListenersService: ' . $turnStartListener::class . ' failed for conversation '
                    . ($conversation->id ?? 'transient') . ': ' . $listenerError->getMessage()
                );
            }
        }
        return $anyRowPersisted;
    }
}
