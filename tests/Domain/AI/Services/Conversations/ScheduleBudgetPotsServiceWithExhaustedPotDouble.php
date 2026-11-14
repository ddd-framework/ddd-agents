<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleBudgetPotStatus;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** A schedule budget pot that is used up. */
class ScheduleBudgetPotsServiceWithExhaustedPotDouble extends ScheduleBudgetPotsService
{
    public function findScheduleBudgetPotStatusForAccount(Account $account): ScheduleBudgetPotStatus
    {
        $scheduleBudgetPotStatus = ScheduleBudgetPotStatus::newInstance();
        $scheduleBudgetPotStatus->monthlyPotUsd = 10.0;
        $scheduleBudgetPotStatus->spentUsd = 10.0;
        return $scheduleBudgetPotStatus;
    }
}
