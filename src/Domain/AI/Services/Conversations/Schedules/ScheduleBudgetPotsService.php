<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleBudgetPotStatus;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The SCHEDULE BUDGET POT of an account: how much of the monthly AI budget the account's scheduled and background
 * runs may spend this calendar month, and how much they spent ({@see ScheduleBudgetPotStatus}). The pot is
 * `monthly budget × pot share`; the spend is the month-to-date cost of every schedule-initiated run tree of the
 * accounts that share the budget.
 *
 * The budget, the pot share, the display policy and the budget family are the application's: the base answers "no
 * budget" (a zero pot that is never exhausted — nothing is capped) through four override points
 * ({@see findMonthlyBudgetForAccount()}, {@see findPotSharePercentForAccount()}, {@see hidesExactFiguresForAccount()},
 * {@see findAccountIdsPoolingBudgetWithAccount()}) plus {@see findEligibilityForDisplay()}. An application overrides
 * them in a same-path subclass, or the whole computation.
 */
class ScheduleBudgetPotsService extends Service
{
    /**
     * The current calendar month's first second as the SQL datetime string the schedule-pot queries compare against —
     * ONE definition for the spend sum and the once-per-month owner-notice dedupe.
     *
     * @return string `Y-m-01 00:00:00` of the current month
     */
    public static function getCurrentCalendarMonthStartSqlDateTime(): string
    {
        return (new DateTime('first day of this month'))->format('Y-m-01 00:00:00');
    }

    /**
     * The pot's full picture for `$account`: pot = monthly budget × pot share, spend = the month-to-date cost of the
     * schedule-initiated runs of the accounts pooling the budget (only read when there is a pot). Under a budget-bypass
     * grant the pot reports a zero-pot, never-exhausted status, so a budget lift lifts this cap like every other
     * budget gate. A non-eligible account (display) reads a pot of 0.
     *
     * @param Account $account The account owning the schedules
     * @return ScheduleBudgetPotStatus
     */
    public function findScheduleBudgetPotStatusForAccount(Account $account): ScheduleBudgetPotStatus
    {
        $scheduleBudgetPotStatus = ScheduleBudgetPotStatus::newInstance();
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        if ($aIUsageEnvelopesService->isBudgetBypassGranted()) {
            return $scheduleBudgetPotStatus;
        }
        $scheduleBudgetPotStatus->potSharePercent = max(0, min(100, $this->findPotSharePercentForAccount($account)));
        $scheduleBudgetPotStatus->hideExactFigures = $this->hidesExactFiguresForAccount($account);
        $scheduleBudgetPotStatus->monthlyPotUsd = $this->findMonthlyBudgetForAccount($account)
            * $scheduleBudgetPotStatus->potSharePercent / 100.0;
        if ($scheduleBudgetPotStatus->monthlyPotUsd > 0.0) {
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $scheduleBudgetPotStatus->spentUsd = $aIConversationMessagesService->findScheduleSpendMonthToDateUsdForAccounts(
                $this->findAccountIdsPoolingBudgetWithAccount($account),
                static::getCurrentCalendarMonthStartSqlDateTime()
            );
        }
        $scheduleBudgetPotStatus->eligibility = $this->findEligibilityForDisplay($account);
        if ($scheduleBudgetPotStatus->isScopeNotEligible()) {
            $scheduleBudgetPotStatus->monthlyPotUsd = 0.0;
            $scheduleBudgetPotStatus->spentUsd = 0.0;
        }
        return $scheduleBudgetPotStatus;
    }

    /**
     * Override point: the account's monthly AI budget in USD. Base: 0.0 = no budget configured.
     *
     * @param Account $account The account
     * @return float
     */
    public function findMonthlyBudgetForAccount(Account $account): float
    {
        return 0.0;
    }

    /**
     * Override point: whether no USD / percent figures may reach this account's customer (a fair-use policy). Base:
     * false.
     *
     * @param Account $account The account
     * @return bool
     */
    public function hidesExactFiguresForAccount(Account $account): bool
    {
        return false;
    }

    /**
     * Override point: the share of the monthly budget scheduled runs may spend, in percent (clamped to 0..100). Base: 0.
     *
     * @param Account $account The account
     * @return int
     */
    protected function findPotSharePercentForAccount(Account $account): int
    {
        return 0;
    }

    /**
     * Override point: the ids of every account whose schedule spend counts against this account's pot (a family of
     * accounts sharing one budget). Base: the account alone.
     *
     * @param Account $account The account
     * @return int[]
     */
    protected function findAccountIdsPoolingBudgetWithAccount(Account $account): array
    {
        return isset($account->id) ? [(int)$account->id] : [];
    }

    /**
     * Override point: the account's automation eligibility for the DISPLAY of the pot (a non-eligible account reads a
     * pot of 0). Base: null = not evaluated.
     *
     * @param Account $account The account
     * @return AgentAutomationEligibility|null
     */
    protected function findEligibilityForDisplay(Account $account): ?AgentAutomationEligibility
    {
        return null;
    }
}
