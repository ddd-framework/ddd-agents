<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Schedules;

use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** A budget of 100 USD, a negative share (clamped to 0), a fair-use policy. */
class ScheduleBudgetPotsWithoutShareService extends ScheduleBudgetPotsService
{
    public function findMonthlyBudgetForAccount(Account $account): float
    {
        return 100.0;
    }

    public function hidesExactFiguresForAccount(Account $account): bool
    {
        return true;
    }

    protected function findPotSharePercentForAccount(Account $account): int
    {
        return -5;
    }
}
