<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;

/** The schedules service of an application with its own words for the pot and the busy run; writes kept in memory. */
class SchedulesServiceWithOwnPotAndRunWordsDouble extends AIConversationSchedulesService
{
    public const string OCCURRENCE_SKIP_REASON_BUDGET_POT_EXHAUSTED = 'SHOP_BUDGET_PAUSED';

    public const string OCCURRENCE_SKIP_REASON_PREVIOUS_RUN_IN_PROGRESS = 'SHOP_RUN_STILL_BUSY';

    public int $refundedOccurrencesCount = 0;

    protected function findAgentSpaceOfSchedule(AIConversationSchedule $schedule): ?AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = 7;
        return $agentSpace;
    }

    public function refundClaimedOccurrence(AIConversationSchedule $schedule, bool $reArmNow): void
    {
        $this->refundedOccurrencesCount++;
    }

    protected function notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhausted(AIConversationSchedule $schedule): void
    {
    }
}
