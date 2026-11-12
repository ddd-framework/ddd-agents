<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TurnEvents;

use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEvents;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * The turn events of one conversation (or one turn of it), oldest first — the inspector's per-turn read.
 *
 * @property AIConversationTurnEvent[] $elements
 * @method AIConversationTurnEvent[] getElements()
 * @method AIConversationTurnEvent|null first()
 * @method static AIConversationTurnEventsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationTurnEvents::class)]
class AIConversationTurnEvents extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AIConversationTurnEventsService::class;

    /** @return AIConversationTurnEvent[] The events of one kind, in order. */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->getElements(), static fn (AIConversationTurnEvent $event): bool => $event->kind === $kind));
    }
}
