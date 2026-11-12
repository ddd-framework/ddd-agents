<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The projected monthly cost of the ACTIVE recurring task schedules of one space — the informational layer of the
 * proactivity budget guards: surfaced to the model in the create/list tool
 * responses so it can steer the customer toward fewer/rarer crons BEFORE the budget drains. Firing counts come
 * from iterating each cron ({@see AIConversationSchedule::countCronFiringsWithinDays()}); per-run cost is the
 * schedule's own run-history average, falling back to the schedule-run average of the space, falling back to a
 * conservative assumption — {@see $isCostAssumed} says which, and the summary marks an assumed figure as a
 * rough estimate. The HARD guards (cron frequency floor, daily creation cap) deliberately do not depend on
 * these estimates — they are pure counts.
 */
class RecurringScheduleMonthlyCostEstimate extends ValueObject
{
    /** @var float Warn tier: the model should INFORM the user and offer lower-frequency alternatives. */
    public const float BUDGET_SHARE_INFORM_THRESHOLD = 0.30;

    /** @var float Strong tier: the model must get the user's explicit confirmation (or delete the schedule again). */
    public const float BUDGET_SHARE_CONFIRM_THRESHOLD = 0.60;

    /**
     * @var float Per-run cost assumption when NO run history exists anywhere in the space — deliberately
     * conservative (typical agent runs cost a few cents; the per-turn cap bounds any run at $2.00).
     */
    public const float ASSUMED_COST_PER_RUN_USD_WITHOUT_HISTORY = 0.25;

    /** @var int How many ACTIVE recurring schedules the space has (what the estimate covers). */
    public int $activeRecurringScheduleCount = 0;

    /** @var int Their summed firings over the next 30 days. */
    public int $estimatedFiringsPerMonth = 0;

    /** @var float The projected monthly spend of those firings in USD. */
    public float $estimatedMonthlyCostUsd = 0.0;

    /** @var bool True when NO run history backed the per-run cost (the assumption was used) — the summary then says "rough estimate". */
    public bool $isCostAssumed = false;

    /** @var float The account's monthly AI budget in USD (0 = none configured). */
    public float $monthlyBudgetUsd = 0.0;

    /** @var bool True when the account is on a fair-use / hidden-usage policy — the summary must not quote USD figures. */
    public bool $hideExactFigures = false;

    /**
     * @var ScheduleMonthlyCostEstimate[] The per-schedule slices of this estimate —
     * one per ACTIVE recurring schedule, exact figures always (the customer DTO applies the fair-use nulling).
     */
    public array $perScheduleEstimates = [];

    /** The projected share of the monthly budget (0..1); null when no budget is configured. */
    public function getShareOfMonthlyBudget(): ?float
    {
        if ($this->monthlyBudgetUsd <= 0.0) {
            return null;
        }
        return $this->estimatedMonthlyCostUsd / $this->monthlyBudgetUsd;
    }

    /**
     * The agent-facing summary line for the create/list tool responses — pure composition over own fields.
     * With exact figures (default) it quotes USD and the budget share; under a fair-use / hidden-usage policy
     * ({@see $hideExactFigures}) it speaks ONLY in qualitative levels (low / elevated / high), never numbers.
     * Above the INFORM threshold it instructs the model to inform the user and offer alternatives; above the
     * CONFIRM threshold it demands the user's explicit confirmation. Empty when no recurring schedule exists.
     */
    public function buildAgentFacingSummary(): string
    {
        if ($this->activeRecurringScheduleCount === 0) {
            return '';
        }
        $shareOfMonthlyBudget = $this->getShareOfMonthlyBudget();

        if ($this->hideExactFigures) {
            $loadLevel = 'low';
            if ($shareOfMonthlyBudget !== null && $shareOfMonthlyBudget > self::BUDGET_SHARE_CONFIRM_THRESHOLD) {
                $loadLevel = 'high';
            } elseif ($shareOfMonthlyBudget !== null && $shareOfMonthlyBudget > self::BUDGET_SHARE_INFORM_THRESHOLD) {
                $loadLevel = 'elevated';
            }
            $qualitativeSummary = "Recurring-task load: {$loadLevel} ({$this->activeRecurringScheduleCount} active recurring "
                . "schedule(s), ~{$this->estimatedFiringsPerMonth} runs/month). Do NOT quote budget numbers to this customer.";
            if ($loadLevel === 'high') {
                return $qualitativeSummary . ' This is a HIGH automation load — before keeping it, get the user\'s explicit'
                    . ' confirmation and offer to reduce frequency or merge/delete schedules (getTaskSchedules / deleteTaskSchedule).';
            }
            if ($loadLevel === 'elevated') {
                return $qualitativeSummary . ' Inform the user that their scheduled tasks add up, and offer lower-frequency'
                    . ' alternatives (weekly instead of daily, merging similar tasks).';
            }
            return $qualitativeSummary;
        }

        $estimateQualifier = $this->isCostAssumed ? 'rough estimate, no run history yet' : 'based on past runs';
        $exactSummary = sprintf(
            'Recurring-task budget projection: %d active recurring schedule(s), ~%d runs/month, ~$%.2f/month (%s)',
            $this->activeRecurringScheduleCount,
            $this->estimatedFiringsPerMonth,
            $this->estimatedMonthlyCostUsd,
            $estimateQualifier
        );
        if ($shareOfMonthlyBudget !== null) {
            $exactSummary .= sprintf(' = %.0f%% of the monthly AI budget ($%.2f)', $shareOfMonthlyBudget * 100, $this->monthlyBudgetUsd);
        }
        $exactSummary .= '.';
        if ($shareOfMonthlyBudget !== null && $shareOfMonthlyBudget > self::BUDGET_SHARE_CONFIRM_THRESHOLD) {
            return $exactSummary . ' That is MORE THAN HALF the budget — get the user\'s explicit confirmation that this is'
                . ' wanted; otherwise reduce frequency or delete schedules (getTaskSchedules / deleteTaskSchedule).';
        }
        if ($shareOfMonthlyBudget !== null && $shareOfMonthlyBudget > self::BUDGET_SHARE_INFORM_THRESHOLD) {
            return $exactSummary . ' Inform the user of this share and offer lower-frequency alternatives (weekly instead'
                . ' of daily, merging similar tasks).';
        }
        return $exactSummary;
    }
}
