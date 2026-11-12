<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TraceEvents;

use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEvents;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * The trace events of one conversation (or one call of it) in time order — the admin inspector's trace read.
 *
 * @property AIConversationTraceEvent[] $elements
 * @method AIConversationTraceEvent[] getElements()
 * @method AIConversationTraceEvent|null first()
 * @method static AIConversationTraceEventsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationTraceEvents::class)]
class AIConversationTraceEvents extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AIConversationTraceEventsService::class;

    /** @return AIConversationTraceEvent[] The events of one type, in order. */
    public function ofEventType(string $eventType): array
    {
        return array_values(array_filter(
            $this->getElements(),
            static fn(AIConversationTraceEvent $traceEvent): bool => $traceEvent->eventType === $eventType
        ));
    }
}
