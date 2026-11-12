<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** No budget, an ended account. */
class ScheduleBudgetPotsOfANonEligibleAccountService extends ScheduleBudgetPotsService
{
    protected function findEligibilityForDisplay(Account $account): ?AgentAutomationEligibility
    {
        $agentAutomationEligibility = AgentAutomationEligibility::eligible(31);
        $agentAutomationEligibility->state = AgentAutomationEligibility::STATE_ENDED;
        return $agentAutomationEligibility;
    }
}
