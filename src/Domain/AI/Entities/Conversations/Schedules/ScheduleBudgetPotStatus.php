<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The SCHEDULE BUDGET POT's current picture for one account and calendar month: the pot is a share of the account's
 * monthly AI budget, and ALL schedule-initiated spend (recurring firings, timed one-times, immediate background tasks —
 * including their delegation children) counts against it. A CAP, not a reservation: interactive chat may spend into
 * the pot's share, autonomous runs never past it. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService::findScheduleBudgetPotStatusForAccount()};
 * consumed by the fire path (skip when exhausted), the task tools (refuse creates/fires and relay the summary so the
 * agent can warn the user) and the customer task list (banner / warn line).
 */
class ScheduleBudgetPotStatus extends ValueObject
{
    /** @var float The agent relays a warning to the user from this share of the pot on (50%). */
    public const float POT_SHARE_WARN_THRESHOLD = 0.50;

    /** @var float The pot in USD this month (share × monthly budget; 0 = no budget configured → nothing to cap against). */
    public float $monthlyPotUsd = 0.0;

    /** @var float Month-to-date SCHEDULE-initiated spend in USD (run trees incl. delegation children; budget-bypass runs excluded). */
    public float $spentUsd = 0.0;

    /** @var int The configured pot share of the monthly budget in percent. */
    public int $potSharePercent = 0;

    /** @var bool True when the account is on a fair-use / hidden-usage policy — no USD/percent figures reach the customer. */
    public bool $hideExactFigures = false;

    /**
     * The account's automation eligibility — DISPLAY only: a non-eligible account reads a pot of 0 and
     * {@see buildAgentFacingSummary()} states the account state first. {@see isExhausted()} is deliberately untouched:
     * the fire path has its own eligibility gate ahead of the pot check. Null = not evaluated.
     */
    public ?AgentAutomationEligibility $eligibility = null;

    /** Whether the display must show the account state instead of pot figures. */
    public function isScopeNotEligible(): bool
    {
        return $this->eligibility !== null && !$this->eligibility->isEligible();
    }

    /** The used share of the pot (0..; >1 = exhausted); null when no budget/pot is configured. */
    public function getShareOfPotUsed(): ?float
    {
        if ($this->monthlyPotUsd <= 0.0) {
            return null;
        }
        return $this->spentUsd / $this->monthlyPotUsd;
    }

    /** True when the pot is spent — the fire path skips occurrences and the tools refuse new autonomous work. */
    public function isExhausted(): bool
    {
        return $this->monthlyPotUsd > 0.0 && $this->spentUsd >= $this->monthlyPotUsd;
    }

    /** True from the warn threshold on (and while not yet exhausted) — the "tell the user now" tier. */
    public function isWarnThresholdReached(): bool
    {
        $shareOfPotUsed = $this->getShareOfPotUsed();
        return $shareOfPotUsed !== null && $shareOfPotUsed >= self::POT_SHARE_WARN_THRESHOLD && !$this->isExhausted();
    }

    /**
     * The agent-facing pot line for the task-tool responses — pure composition over own fields. Below the warn
     * threshold it is EMPTY (no noise on every list call). In the warn tier it instructs the agent to inform the
     * user; exhausted, it states the stop and the reset. Under fair-use ({@see $hideExactFigures}) it never
     * quotes USD or percent — qualitative wording only.
     */
    public function buildAgentFacingSummary(): string
    {
        if ($this->isScopeNotEligible()) {
            return $this->eligibility->describeForModel();
        }
        if ($this->isExhausted()) {
            if ($this->hideExactFigures) {
                return 'The automation budget for this month is USED UP — scheduled/background tasks pause until the 1st'
                    . ' of next month (interactive chat keeps working). Tell the user this in plain words; do NOT quote'
                    . ' budget numbers to this customer.';
            }
            return sprintf(
                'The automation budget for this month is USED UP ($%.2f of $%.2f, %d%% of the monthly AI budget) —'
                . ' scheduled/background tasks pause until the 1st of next month (interactive chat keeps working).'
                . ' Tell the user this in plain words.',
                $this->spentUsd,
                $this->monthlyPotUsd,
                $this->potSharePercent
            );
        }
        if (!$this->isWarnThresholdReached()) {
            return '';
        }
        if ($this->hideExactFigures) {
            return 'The automation budget for this month is running low. Inform the user that their'
                . ' scheduled/background tasks may pause before the month ends; do NOT quote budget numbers to this'
                . ' customer.';
        }
        return sprintf(
            'The automation budget for this month is %.0f%% used ($%.2f of $%.2f) — when it runs out,'
            . ' scheduled/background tasks pause until the 1st while interactive chat keeps working. Inform the user.',
            ($this->getShareOfPotUsed() ?? 0.0) * 100,
            $this->spentUsd,
            $this->monthlyPotUsd
        );
    }
}
