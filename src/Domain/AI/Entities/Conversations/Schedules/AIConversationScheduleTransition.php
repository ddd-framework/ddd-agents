<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use InvalidArgumentException;

/**
 * Whether ONE operation on a schedule is allowed in the schedule's current state, and what it leads to: the refusal reason when it is not, the status the schedule moves to, the state
 * reason the platform stamps, the delete outcome, and whether the fire anchor is re-armed from now. Built by
 * {@see AIConversationSchedule::buildTransitionTo()}; every caller — the customer endpoints, the agent's tools, the
 * admin console and the fire / sweep commands — asks here, and all of them get the same answer
 * (one table, no per-surface variants).
 *
 * Pure decision over the schedule's own state, the scope's eligibility and the clock the caller passes — no repo,
 * no world. The refusal reason constant tells a caller which text applies; the texts live on the exceptions the
 * service and the callers throw.
 *
 * The rules in short:
 * - pause: ACTIVE only; cancel: ACTIVE or PAUSED;
 * - resume: an owner-PAUSED row on an eligible scope, and a one-time row only while its moment lies ahead;
 * - delete: the archive policy (never ran → removed; recurring that ran → ARCHIVED; one-time that ran → kept);
 * - every edit: ACTIVE or PAUSED only; a cron belongs to a recurring row, a moment to a one-time row that has not run,
 *   and a one-time row's zone moves only together with a new moment;
 * - run now (a person fires an existing row — the customer and the admin alike): ACTIVE only (a PAUSED row is
 *   refused), not expired, recurring, at most once per
 *   {@see MANUAL_FIRE_MINIMUM_INTERVAL_SECONDS}; start now (the first firing of a row created to run at once): ACTIVE,
 *   not expired, one-time with its moment due, never ran;
 * - EXPIRED, DONE and the state sweep's statuses are reached through the service's status transitions only; the
 *   sweep closes a platform-paused one-time row whose moment passed as EXPIRED instead of re-arming it.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationScheduleTransition extends ValueObject
{
    /** @var string ACTIVE → PAUSED by the owner or an operator. */
    public const string OPERATION_PAUSE = 'PAUSE';

    /** @var string PAUSED → ACTIVE by the owner or an operator; the fire anchor is re-armed from now. */
    public const string OPERATION_RESUME = 'RESUME';

    /** @var string ACTIVE / PAUSED → CANCELLED by the owner or an operator. */
    public const string OPERATION_CANCEL = 'CANCEL';

    /** @var string The delete request — its outcome is {@see $deleteAction}. */
    public const string OPERATION_DELETE = 'DELETE';

    /** @var string Any edit of the row's columns; an ACTIVE row re-arms its fire anchor from edited timing. */
    public const string OPERATION_EDIT = 'EDIT';

    /** @var string An edit of the cron expression. */
    public const string OPERATION_EDIT_CRON = 'EDIT_CRON';

    /** @var string An edit of the time zone alone. */
    public const string OPERATION_EDIT_TIMEZONE = 'EDIT_TIMEZONE';

    /** @var string An edit of the time zone together with a new one-time moment in the same call. */
    public const string OPERATION_EDIT_TIMEZONE_WITH_NEW_MOMENT = 'EDIT_TIMEZONE_WITH_NEW_MOMENT';

    /** @var string An edit of the one-time moment. */
    public const string OPERATION_EDIT_ONE_TIME_MOMENT = 'EDIT_ONE_TIME_MOMENT';

    /** @var string A person fires an existing row's next occurrence now, outside the fire anchor. */
    public const string OPERATION_RUN_NOW = 'RUN_NOW';

    /** @var string The first firing of a one-time row created to run at once (the agent's background task). */
    public const string OPERATION_START_NOW = 'START_NOW';

    /** @var string The fire path closes a row past its ttl or its maximum firings as EXPIRED. */
    public const string OPERATION_EXPIRE = 'EXPIRE';

    /** @var string A completed run fulfilled the row's done criteria → DONE. */
    public const string OPERATION_DONE_BY_CRITERIA = 'DONE_BY_CRITERIA';

    /** @var string The single execution of a one-time row terminated → DONE. */
    public const string OPERATION_DONE_AFTER_ONE_TIME_RUN = 'DONE_AFTER_ONE_TIME_RUN';

    /** @var string The platform's transition for the scope's automation state (the four-row table). */
    public const string OPERATION_STATE_SWEEP = 'STATE_SWEEP';

    /** @var string The row's status does not allow the operation. */
    public const string REFUSAL_STATUS = 'STATUS';

    /** @var string A resume of a row the platform transitioned for a state of the account or the agent space ({@see AIConversationSchedule::$stateReason}). */
    public const string REFUSAL_CLOSED_BY_PLATFORM_STATE = 'CLOSED_BY_PLATFORM_STATE';

    /** @var string A resume on an agent space / account that is not eligible for automated AI work. */
    public const string REFUSAL_SCOPE_NOT_ELIGIBLE = 'SCOPE_NOT_ELIGIBLE';

    /** @var string A cron on a one-time row. */
    public const string REFUSAL_ONE_TIME_HAS_NO_CRON = 'ONE_TIME_HAS_NO_CRON';

    /** @var string A zone on a one-time row without a new moment in the same call — a bare zone flip would move a confirmed instant. */
    public const string REFUSAL_ONE_TIME_ZONE_NEEDS_NEW_MOMENT = 'ONE_TIME_ZONE_NEEDS_NEW_MOMENT';

    /** @var string A one-time moment on a recurring row. */
    public const string REFUSAL_RECURRING_HAS_NO_ONE_TIME_MOMENT = 'RECURRING_HAS_NO_ONE_TIME_MOMENT';

    /** @var string A new moment on a one-time row that already ran. */
    public const string REFUSAL_ONE_TIME_ALREADY_RAN = 'ONE_TIME_ALREADY_RAN';

    /** @var string A resume of a one-time row whose moment has passed — re-arming it would fire a past moment. */
    public const string REFUSAL_ONE_TIME_MOMENT_PASSED = 'ONE_TIME_MOMENT_PASSED';

    /** @var string A manual fire of a one-time row — it would consume its single occurrence early. */
    public const string REFUSAL_ONE_TIME_FIRES_AT_ITS_MOMENT = 'ONE_TIME_FIRES_AT_ITS_MOMENT';

    /** @var string A fire of a row past its ttl or its maximum firings — the fire path closes it as EXPIRED instead. */
    public const string REFUSAL_EXPIRED = 'EXPIRED';

    /** @var string A start-now of a row that already fired — only the first firing of a row created to run at once starts. */
    public const string REFUSAL_ALREADY_FIRED = 'ALREADY_FIRED';

    /** @var string A manual fire within {@see MANUAL_FIRE_MINIMUM_INTERVAL_SECONDS} of the last firing. */
    public const string REFUSAL_MANUAL_FIRE_INTERVAL = 'MANUAL_FIRE_INTERVAL';

    /** @var string The fire path's expiry: neither the ttl nor the maximum firings are reached. */
    public const string REFUSAL_NOT_EXPIRED = 'NOT_EXPIRED';

    /** @var string The row has no done criteria to judge. */
    public const string REFUSAL_NO_DONE_CRITERIA = 'NO_DONE_CRITERIA';

    /** @var string An operation that only a one-time row has — a done transition after its run, a start-now. */
    public const string REFUSAL_NOT_ONE_TIME = 'NOT_ONE_TIME';

    /** @var string A start-now of a one-time row whose moment still lies ahead — it fires at that moment, not now. */
    public const string REFUSAL_ONE_TIME_MOMENT_NOT_DUE = 'ONE_TIME_MOMENT_NOT_DUE';

    /** @var string The state sweep leaves the row exactly as it is. */
    public const string REFUSAL_STATE_SWEEP_LEAVES_ROW = 'STATE_SWEEP_LEAVES_ROW';

    /** @var int The floor between two firings of the same schedule when one is fired by hand ("Jetzt ausführen", the admin fire-now). */
    public const int MANUAL_FIRE_MINIMUM_INTERVAL_SECONDS = 3600;

    /** @var string[] The live statuses — a schedule is edited or cancelled only in these; every other status is final. Run-now and start-now need ACTIVE. */
    public const array LIVE_STATUSES = [AIConversationScheduleStatus::ACTIVE, AIConversationScheduleStatus::PAUSED];

    /** @var string One of the OPERATION_* constants. */
    public string $operation = self::OPERATION_PAUSE;

    /** @var bool Whether the operation may run on the row as it is. */
    public bool $isAllowed = false;

    /** @var string|null One of the REFUSAL_* constants when not allowed. */
    public ?string $refusalReason = null;

    /** @var string|null The {@see AIConversationScheduleStatus} the row moves to; null when the status stays. */
    public ?string $targetStatus = null;

    /** @var string|null The state reason a state-sweep transition writes with the status (null = cleared). */
    public ?string $stateReason = null;

    /** @var string|null The delete outcome (one of the AIConversationSchedule::DELETE_ACTION_* constants). */
    public ?string $deleteAction = null;

    /** @var bool Whether the fire anchor is recomputed from now (a row that enters or stays ACTIVE with new timing). */
    public bool $reArmsFireAnchor = false;

    /** @var int|null Seconds until a manual fire is allowed again ({@see REFUSAL_MANUAL_FIRE_INTERVAL}). */
    public ?int $secondsUntilManualFireIsAllowed = null;

    /**
     * The transition of `$operation` on `$schedule` — `$eligibility` is the scope's freshly read automation
     * eligibility where the operation depends on it (resume, state sweep), `$now` the clock.
     */
    public static function decide(
        AIConversationSchedule $schedule,
        string $operation,
        DateTime $now,
        ?AgentAutomationEligibility $eligibility = null
    ): static {
        $transition = new static();
        $transition->operation = $operation;
        match ($operation) {
            self::OPERATION_PAUSE => $transition->decidePause($schedule),
            self::OPERATION_RESUME => $transition->decideResume($schedule, $eligibility, $now),
            self::OPERATION_CANCEL => $transition->decideCancel($schedule),
            self::OPERATION_DELETE => $transition->decideDelete($schedule),
            self::OPERATION_EDIT => $transition->decideEdit($schedule),
            self::OPERATION_EDIT_CRON => $transition->decideCronEdit($schedule),
            self::OPERATION_EDIT_TIMEZONE => $transition->decideTimezoneEdit($schedule, false),
            self::OPERATION_EDIT_TIMEZONE_WITH_NEW_MOMENT => $transition->decideTimezoneEdit($schedule, true),
            self::OPERATION_EDIT_ONE_TIME_MOMENT => $transition->decideOneTimeMomentEdit($schedule),
            self::OPERATION_RUN_NOW => $transition->decideRunNow($schedule, $now),
            self::OPERATION_START_NOW => $transition->decideStartNow($schedule, $now),
            self::OPERATION_EXPIRE => $transition->decideExpiry($schedule, $now),
            self::OPERATION_DONE_BY_CRITERIA => $transition->decideDoneByCriteria($schedule),
            self::OPERATION_DONE_AFTER_ONE_TIME_RUN => $transition->decideDoneAfterOneTimeRun($schedule),
            self::OPERATION_STATE_SWEEP => $transition->decideStateSweep($schedule, $eligibility, $now),
            default => throw new InvalidArgumentException("Unknown schedule operation '{$operation}'."),
        };
        return $transition;
    }

    protected function allowTo(?string $targetStatus): void
    {
        $this->isAllowed = true;
        $this->refusalReason = null;
        $this->targetStatus = $targetStatus;
    }

    protected function refuseFor(string $refusalReason): void
    {
        $this->isAllowed = false;
        $this->refusalReason = $refusalReason;
        $this->targetStatus = null;
        $this->reArmsFireAnchor = false;
    }

    /** Whether the row is still live (ACTIVE or PAUSED) — every other status is final. */
    protected static function isLive(AIConversationSchedule $schedule): bool
    {
        return in_array($schedule->status, self::LIVE_STATUSES, true);
    }

    /** Only an ACTIVE row pauses (a one-time row that has not run yet included). */
    protected function decidePause(AIConversationSchedule $schedule): void
    {
        if (!$schedule->isActive()) {
            $this->refuseFor(self::REFUSAL_STATUS);
            return;
        }
        $this->allowTo(AIConversationScheduleStatus::PAUSED);
    }

    /**
     * Only a PAUSED row the OWNER paused resumes — a row the platform paused for a locked agent space / account is
     * re-armed by the sweep when the state clears, never by hand. A refused row the platform
     * transitioned names that state, whatever its status. A one-time row resumes only while its moment lies ahead:
     * re-arming a past moment fired it at the next due scan or had it expired. Given the scope's eligibility, the
     * scope must be eligible.
     */
    protected function decideResume(AIConversationSchedule $schedule, ?AgentAutomationEligibility $eligibility, DateTime $now): void
    {
        if ($schedule->status !== AIConversationScheduleStatus::PAUSED || $schedule->isClosedByPlatformState()) {
            $this->refuseFor($schedule->isClosedByPlatformState() ? self::REFUSAL_CLOSED_BY_PLATFORM_STATE : self::REFUSAL_STATUS);
            return;
        }
        if ($schedule->isOneTime() && $schedule->oneTimeAt->getTimestamp() <= $now->getTimestamp()) {
            $this->refuseFor(self::REFUSAL_ONE_TIME_MOMENT_PASSED);
            return;
        }
        if ($eligibility !== null && !$eligibility->isEligible()) {
            $this->refuseFor(self::REFUSAL_SCOPE_NOT_ELIGIBLE);
            return;
        }
        $this->allowTo(AIConversationScheduleStatus::ACTIVE);
        $this->reArmsFireAnchor = true;
    }

    /** Any non-terminal row cancels; a terminal row stays terminal. */
    protected function decideCancel(AIConversationSchedule $schedule): void
    {
        if (!self::isLive($schedule)) {
            $this->refuseFor(self::REFUSAL_STATUS);
            return;
        }
        $this->allowTo(AIConversationScheduleStatus::CANCELLED);
    }

    /**
     * The archive policy, no status check, for every caller: never ran → hard delete; a
     * recurring row that ran → ARCHIVED; a one-time row that ran stays as history.
     */
    protected function decideDelete(AIConversationSchedule $schedule): void
    {
        if ($schedule->iterationsUsed === 0) {
            $this->deleteAction = AIConversationSchedule::DELETE_ACTION_HARD_DELETE;
            $this->allowTo(null);
            return;
        }
        if ($schedule->isOneTime()) {
            $this->deleteAction = AIConversationSchedule::DELETE_ACTION_KEEP_HISTORY;
            $this->allowTo(null);
            return;
        }
        $this->deleteAction = AIConversationSchedule::DELETE_ACTION_ARCHIVE;
        $this->allowTo(AIConversationScheduleStatus::ARCHIVED);
    }

    /** Only a live row (ACTIVE or PAUSED) is edited; an ACTIVE row re-arms its fire anchor from edited timing. */
    protected function decideEdit(AIConversationSchedule $schedule): void
    {
        if (!self::isLive($schedule)) {
            $this->refuseFor(self::REFUSAL_STATUS);
            return;
        }
        $this->allowTo(null);
        $this->reArmsFireAnchor = $schedule->isActive();
    }

    /** A cron belongs to a live recurring row. */
    protected function decideCronEdit(AIConversationSchedule $schedule): void
    {
        $this->decideEdit($schedule);
        if ($this->isAllowed && $schedule->isOneTime()) {
            $this->refuseFor(self::REFUSAL_ONE_TIME_HAS_NO_CRON);
        }
    }

    /**
     * A zone belongs to a live row. A one-time row's zone moves only together with a new moment in the same call — a
     * bare zone flip would move an already confirmed instant.
     */
    protected function decideTimezoneEdit(AIConversationSchedule $schedule, bool $togetherWithNewMoment): void
    {
        $this->decideEdit($schedule);
        if ($this->isAllowed && $schedule->isOneTime() && !$togetherWithNewMoment) {
            $this->refuseFor(self::REFUSAL_ONE_TIME_ZONE_NEEDS_NEW_MOMENT);
        }
    }

    /** A new moment belongs to a live one-time row that has not run yet. */
    protected function decideOneTimeMomentEdit(AIConversationSchedule $schedule): void
    {
        $this->decideEdit($schedule);
        if (!$this->isAllowed) {
            return;
        }
        if (!$schedule->isOneTime()) {
            $this->refuseFor(self::REFUSAL_RECURRING_HAS_NO_ONE_TIME_MOMENT);
            return;
        }
        if ($schedule->iterationsUsed > 0) {
            $this->refuseFor(self::REFUSAL_ONE_TIME_ALREADY_RAN);
        }
    }

    /**
     * A person fires an existing row now: never a one-time row (its single occurrence would be consumed early), only
     * an ACTIVE row, never an expired one, and at most once per {@see MANUAL_FIRE_MINIMUM_INTERVAL_SECONDS} after the
     * last firing.
     */
    protected function decideRunNow(AIConversationSchedule $schedule, DateTime $now): void
    {
        if ($schedule->isOneTime()) {
            $this->refuseFor(self::REFUSAL_ONE_TIME_FIRES_AT_ITS_MOMENT);
            return;
        }
        if (!$schedule->isActive()) {
            $this->refuseFor(self::REFUSAL_STATUS);
            return;
        }
        if ($schedule->isExpired($now)) {
            $this->refuseFor(self::REFUSAL_EXPIRED);
            return;
        }
        $lastFiredAt = $schedule->lastFiredAt ?? null;
        if ($lastFiredAt !== null) {
            $secondsSinceLastFire = $now->getTimestamp() - $lastFiredAt->getTimestamp();
            if ($secondsSinceLastFire < self::MANUAL_FIRE_MINIMUM_INTERVAL_SECONDS) {
                $this->refuseFor(self::REFUSAL_MANUAL_FIRE_INTERVAL);
                $this->secondsUntilManualFireIsAllowed = self::MANUAL_FIRE_MINIMUM_INTERVAL_SECONDS - $secondsSinceLastFire;
                return;
            }
        }
        $this->allowTo(null);
    }

    /**
     * The first firing of a row created to run at once: ACTIVE, not expired, a one-time row whose moment is due (the
     * creating tool stamps it with now), never fired. A recurring row or a moment ahead is never started early.
     */
    protected function decideStartNow(AIConversationSchedule $schedule, DateTime $now): void
    {
        if (!$schedule->isActive()) {
            $this->refuseFor(self::REFUSAL_STATUS);
            return;
        }
        if ($schedule->isExpired($now)) {
            $this->refuseFor(self::REFUSAL_EXPIRED);
            return;
        }
        if (!$schedule->isOneTime()) {
            $this->refuseFor(self::REFUSAL_NOT_ONE_TIME);
            return;
        }
        if ($schedule->oneTimeAt->getTimestamp() > $now->getTimestamp()) {
            $this->refuseFor(self::REFUSAL_ONE_TIME_MOMENT_NOT_DUE);
            return;
        }
        if ($schedule->iterationsUsed > 0) {
            $this->refuseFor(self::REFUSAL_ALREADY_FIRED);
            return;
        }
        $this->allowTo(null);
    }

    protected function decideExpiry(AIConversationSchedule $schedule, DateTime $now): void
    {
        if (!$schedule->isExpired($now)) {
            $this->refuseFor(self::REFUSAL_NOT_EXPIRED);
            return;
        }
        $this->allowTo(AIConversationScheduleStatus::EXPIRED);
    }

    /** A pause or cancel between the fire and the run terminal stands: only an ACTIVE row with done criteria closes. */
    protected function decideDoneByCriteria(AIConversationSchedule $schedule): void
    {
        if (trim((string)($schedule->doneCriteria ?? '')) === '') {
            $this->refuseFor(self::REFUSAL_NO_DONE_CRITERIA);
            return;
        }
        if (!$schedule->isActive()) {
            $this->refuseFor(self::REFUSAL_STATUS);
            return;
        }
        $this->allowTo(AIConversationScheduleStatus::DONE);
    }

    /** The single execution of an ACTIVE one-time row closes it, success or failure alike; a paused row stays paused. */
    protected function decideDoneAfterOneTimeRun(AIConversationSchedule $schedule): void
    {
        if (!$schedule->isOneTime()) {
            $this->refuseFor(self::REFUSAL_NOT_ONE_TIME);
            return;
        }
        if (!$schedule->isActive()) {
            $this->refuseFor(self::REFUSAL_STATUS);
            return;
        }
        $this->allowTo(AIConversationScheduleStatus::DONE);
    }

    /**
     * THE four-row rule table of the state sweep:
     *
     *   ACTIVE          + ENDED / ERASED      → CANCELLED, reason          (terminal; the agent space is gone)
     *   ACTIVE          + SUSPENDED           → PAUSED, reason             (reversible; resumes when the state clears)
     *   PAUSED (owner)  + ENDED / ERASED      → CANCELLED, reason          (resumption is impossible anyway)
     *   PAUSED (owner)  + SUSPENDED / ELIGIBLE → untouched                 (the owner's pause stands; no reason stamped)
     *
     * and the two rules that make the reason column safe: a PLATFORM-paused row (reason set) is re-armed — ACTIVE,
     * reason cleared — ONLY when the scope is eligible again, closed when it ended, and left alone while it stays
     * suspended; a row without a reason is never re-armed by the sweep. A platform-paused ONE-TIME row whose moment
     * passed is not re-armed when the scope is eligible again: it closes as EXPIRED, reason cleared (the resume
     * rule). Terminal statuses never move.
     */
    protected function decideStateSweep(AIConversationSchedule $schedule, ?AgentAutomationEligibility $eligibility, DateTime $now): void
    {
        $this->refuseFor(self::REFUSAL_STATE_SWEEP_LEAVES_ROW);
        if ($eligibility === null) {
            return;
        }
        $terminalReason = $eligibility->getAutomationStateReason();
        if ($schedule->isActive()) {
            if ($eligibility->isEnded() || $eligibility->isErased()) {
                $this->allowTo(AIConversationScheduleStatus::CANCELLED);
                $this->stateReason = $terminalReason;
            } elseif ($eligibility->isSuspended()) {
                $this->allowTo(AIConversationScheduleStatus::PAUSED);
                $this->stateReason = $terminalReason;
            }
            return;
        }
        if ($schedule->status !== AIConversationScheduleStatus::PAUSED) {
            return;
        }
        if ($eligibility->isEnded() || $eligibility->isErased()) {
            $this->allowTo(AIConversationScheduleStatus::CANCELLED);
            $this->stateReason = $terminalReason;
            return;
        }
        if ($schedule->isClosedByPlatformState() && $eligibility->isEligible()) {
            if ($schedule->isOneTime() && $schedule->oneTimeAt->getTimestamp() <= $now->getTimestamp()) {
                // The resume rule: a one-time row whose moment passed is never re-armed onto it — it would fire
                // late. Closed as EXPIRED, reason cleared; left PAUSED it would stay a sweep candidate forever.
                $this->allowTo(AIConversationScheduleStatus::EXPIRED);
                $this->stateReason = null;
                return;
            }
            $this->allowTo(AIConversationScheduleStatus::ACTIVE);
            $this->stateReason = null;
            $this->reArmsFireAnchor = true;
        }
    }
}
