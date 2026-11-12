<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A space with at least one active schedule that fires within a time window, together with the account that holds
 * those schedules. Built from one row of the read across accounts and spaces
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService::findAgentSpacesWithRunDueBetween()}).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationScheduleDueAgentSpace extends ValueObject
{
    /** @var int The account that holds the schedules */
    public int $accountId = 0;

    /** @var int The space the schedules run in */
    public int $agentSpaceId = 0;

    /**
     * @param int $accountId
     * @param int $agentSpaceId
     * @return static
     */
    public static function forAccountIdAndAgentSpaceId(int $accountId, int $agentSpaceId): static
    {
        $scheduleDueAgentSpace = new static();
        $scheduleDueAgentSpace->accountId = $accountId;
        $scheduleDueAgentSpace->agentSpaceId = $agentSpaceId;
        return $scheduleDueAgentSpace;
    }
}
