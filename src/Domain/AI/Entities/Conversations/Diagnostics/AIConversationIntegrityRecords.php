<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the integrity checks read from a conversation's stored turn events
 * ({@see \DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService::findIntegrityRecordsOfConversation()}):
 * every skill-preload decision, and the index of the first message of every turn that recorded an event.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationIntegrityRecords extends ValueObject
{
    /** @var AIConversationIntegrityPreloadDecision[] Every stored skill-preload decision, in the order they were stored */
    public array $preloadDecisions = [];

    /** @var int[] The index of the message that started each turn that recorded an event, ascending */
    public array $turnInitiatorIndexes = [];

    /**
     * @param AIConversationIntegrityPreloadDecision[] $preloadDecisions
     * @param int[] $turnInitiatorIndexes
     * @return static
     */
    public static function forPreloadDecisionsAndTurnInitiatorIndexes(array $preloadDecisions, array $turnInitiatorIndexes): static
    {
        $integrityRecords = new static();
        $integrityRecords->preloadDecisions = $preloadDecisions;
        $integrityRecords->turnInitiatorIndexes = $turnInitiatorIndexes;
        return $integrityRecords;
    }
}
