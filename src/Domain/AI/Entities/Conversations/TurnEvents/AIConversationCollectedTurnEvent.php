<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TurnEvents;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A turn event that is built but not recorded yet, with the run token its write is checked against. While
 * {@see \DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService::collectEventsOfConversation()}
 * is in effect for a conversation, its events are held as these and recorded afterwards in their order.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCollectedTurnEvent extends ValueObject
{
    /** @var AIConversationTurnEvent|null The event as it will be recorded */
    public ?AIConversationTurnEvent $turnEvent = null;

    /** @var string|null The run token the write of the event is checked against; null for a write without that check */
    public ?string $runToken = null;

    /**
     * @param AIConversationTurnEvent $turnEvent
     * @param string|null $runToken
     * @return static
     */
    public static function forTurnEventAndRunToken(AIConversationTurnEvent $turnEvent, ?string $runToken): static
    {
        $collectedTurnEvent = new static();
        $collectedTurnEvent->turnEvent = $turnEvent;
        $collectedTurnEvent->runToken = $runToken;
        return $collectedTurnEvent;
    }
}
