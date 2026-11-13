<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE `set_task_status` call did to the plan ({@see TaskPlan::applyStatusUpdates()}): the
 * counters of its updates by what decided them, the feedback lines in order, and the updates the cascade cap noted. Its
 * questions are what the plan stamps, the record service's result text and the loop's all-no-op breaker read
 * ({@see requiresToolLessAnswerStep()} — the former by-ref flag of the record service). A working value of one call.
 *
 * @phpstan-consistent-constructor
 */
class AgentTaskPlanTickOutcome extends ValueObject
{
    /** Earlier failed ticks in one turn after which an all-no-op tick forces the tool-less step even beside a `← next` item. */
    public const int LOOPING_FAILED_TICKS_IN_A_TURN = 3;

    /** The feedback line of a call without a readable `updates` list. */
    public const string MISSING_UPDATES_FEEDBACK = "'updates' is missing or not an array — send updates: [{task, status}, …].";

    /** How many updates the call sent (objects or not) — the denominator of {@see isOnlyResolvedByPlatformInThisStep()}. */
    public int $updateCount = 0;

    /** Applied updates, the record's verdict applied in place of a tick included. */
    public int $updatedCount = 0;

    /** Applied updates that moved an item INTO a terminal status — the plan's resolution stamp. */
    public int $resolvedCount = 0;

    /** Ticks the record could not confirm (and the cascade cap's): not applied — a plan touch, never progress. */
    public int $notedCount = 0;

    /** Retryable technical blocks on record-gated leaves, kept open with the obstacle noted. */
    public int $notedObstacleCount = 0;

    /** The true same-status no-ops that carried the lecture (at most one per call). */
    public int $alreadyInStatusCount = 0;

    /** Ticks of items the platform resolved in this turn after the model wrote the response. */
    public int $resolvedByPlatformInThisStepCount = 0;

    /** @var string[] The feedback lines, in order. */
    public array $feedback = [];

    /**
     * @var string[] The addresses (keys) of the updates that resolved an item — applied as a transition into a terminal
     *      status — in update order: what the plan's resolution stamp is for
     *      ({@see resolvesAnItem()}); `update_task_plan` stamps it only for an item that stood before the call.
     */
    public array $resolvingTaskAddresses = [];

    /** @var string[] The addresses of the `blocked` updates the cascade cap noted. */
    public array $blockedCascadeNotedTaskAddresses = [];

    /** @var string[] The addresses of the `not_applicable` updates the cascade cap noted. */
    public array $notApplicableCascadeNotedTaskAddresses = [];

    /** The call carried no readable `updates` list: nothing applies, the feedback says so. */
    public static function withoutUpdatesList(): static
    {
        $agentTaskPlanTickOutcome = new static();
        $agentTaskPlanTickOutcome->feedback = [self::MISSING_UPDATES_FEEDBACK];
        return $agentTaskPlanTickOutcome;
    }

    /** An address of the call is not a key of the plan: nothing applies, the rejection block is the only feedback. */
    public static function rejectedForItsAddresses(string $rejectionBlock): static
    {
        $agentTaskPlanTickOutcome = new static();
        $agentTaskPlanTickOutcome->feedback = [$rejectionBlock];
        return $agentTaskPlanTickOutcome;
    }

    /** A call whose `$updateCount` updates are applied one by one. */
    public static function forUpdateCount(int $updateCount): static
    {
        $agentTaskPlanTickOutcome = new static();
        $agentTaskPlanTickOutcome->updateCount = $updateCount;
        return $agentTaskPlanTickOutcome;
    }

    /** @param string[] $feedbackLines */
    public function addFeedbackLines(array $feedbackLines): void
    {
        foreach ($feedbackLines as $feedbackLine) {
            $this->feedback[] = $feedbackLine;
        }
    }

    /** Count one update's verdict by its kind, note the update's `$taskAddress` when it resolved an item, add its feedback lines. */
    public function addVerdict(TaskPlanItemTickVerdict $taskPlanItemTickVerdict, string $taskAddress = ''): void
    {
        if ($taskPlanItemTickVerdict->kind === TaskPlanItemTickVerdict::KIND_APPLIED_AS_RESOLUTION) {
            $this->resolvingTaskAddresses[] = $taskAddress;
        }
        match ($taskPlanItemTickVerdict->kind) {
            TaskPlanItemTickVerdict::KIND_ECHO_OF_PLATFORM_CLOSING => $this->resolvedByPlatformInThisStepCount++,
            TaskPlanItemTickVerdict::KIND_NOTED => $this->notedCount++,
            TaskPlanItemTickVerdict::KIND_NOTED_OBSTACLE => $this->notedObstacleCount++,
            TaskPlanItemTickVerdict::KIND_RECORD_VERDICT_APPLIED, TaskPlanItemTickVerdict::KIND_APPLIED => $this->updatedCount++,
            TaskPlanItemTickVerdict::KIND_APPLIED_AS_RESOLUTION => $this->countResolution(),
            TaskPlanItemTickVerdict::KIND_ALREADY_IN_STATUS_WITH_LECTURE => $this->alreadyInStatusCount++,
            default => null,
        };
        $this->addFeedbackLines($taskPlanItemTickVerdict->feedbackLines);
    }

    /** One `blocked` (or `not_applicable`) update the cascade cap noted: named in the call's cascade line, counted as noted. */
    public function noteCascadeUpdate(string $status, string $taskAddress): void
    {
        if ($status === TaskPlanItem::STATUS_BLOCKED) {
            $this->blockedCascadeNotedTaskAddresses[] = $taskAddress;
        } else {
            $this->notApplicableCascadeNotedTaskAddresses[] = $taskAddress;
        }
        $this->notedCount++;
    }

    /** The call's cascade lines after every per-update line: the not_applicable sweep first, then the blocked one. */
    public function addCascadeLines(): void
    {
        if ($this->notApplicableCascadeNotedTaskAddresses !== []) {
            $this->feedback[] = 'Not applied — ' . count($this->notApplicableCascadeNotedTaskAddresses) . ' not_applicable update(s) ('
                . implode(', ', $this->notApplicableCascadeNotedTaskAddresses) . ') — that is a give-up cascade, not '
                . count($this->notApplicableCascadeNotedTaskAddresses) . ' verdicts. An item is not_applicable only from a record of THIS ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ' '
                . '(the owner\'s words, a tool result), one item at a time when its turn comes (at most '
                . TaskPlan::MAX_BLOCKED_UPDATES_PER_CALL . ' per call); an act not reached yet stays OPEN — the plan is worked top-down, '
                . 'and the platform closes items from the record itself.';
        }
        if ($this->blockedCascadeNotedTaskAddresses !== []) {
            $this->feedback[] = 'Not applied — ' . count($this->blockedCascadeNotedTaskAddresses) . ' blocked update(s) ('
                . implode(', ', $this->blockedCascadeNotedTaskAddresses) . ') — that is a give-up cascade, not '
                . count($this->blockedCascadeNotedTaskAddresses) . ' real blockers. A cascade has exactly ONE blocker: '
                . 'the step that is actually obstructed (with its concrete reason, at most '
                . TaskPlan::MAX_BLOCKED_UPDATES_PER_CALL . ' blocked items per call); every downstream '
                . 'item stays OPEN until its turn, and the work continues or retries from the obstructed step.';
        }
    }

    /** The call already carried the ALREADY lecture — a later no-op names it in one short line. */
    public function hasLecturedAlreadyInStatus(): bool
    {
        return $this->alreadyInStatusCount > 0;
    }

    /**
     * The call touched the plan in this turn: an applied, NOTED or obstacle-noted update — a NOTED tick on a
     * record-gated leaf is a plan touch too (the checked stop arms on it against a fence-less recap).
     */
    public function touchesThePlan(): bool
    {
        return $this->updatedCount > 0 || $this->notedCount > 0 || $this->notedObstacleCount > 0;
    }

    /** The call moved an item into a terminal status — the model's progress on the plan. */
    public function resolvesAnItem(): bool
    {
        return $this->resolvedCount > 0;
    }

    /**
     * Nothing was applied and at least one same-status no-op was counted (only the no-op answered with the long
     * instruction counts) — a model that re-ticks resolved items is stalling before its owed answer; plan tools are
     * repeat-guard-exempt, so this is the only brake against a re-tick loop.
     */
    public function isAllAlreadyInStatus(): bool
    {
        return $this->updatedCount === 0 && $this->alreadyInStatusCount > 0;
    }

    /** Nothing was updated, refused or corrected: every update named an item the platform resolved after the model wrote the response. */
    public function isOnlyResolvedByPlatformInThisStep(): bool
    {
        return $this->updatedCount === 0
            && $this->resolvedByPlatformInThisStepCount > 0
            && $this->resolvedByPlatformInThisStepCount === $this->updateCount;
    }

    /**
     * The all-no-op tick forces the tool-less closing step when nothing workable is left (`$openItemCount` 0), when the
     * model is looping (an earlier tick of the turn failed) while no item carries `← next` (`$planHasNextMarkedItem`
     * false — every open item waits for the owner), or when the loop is long ({@see self::LOOPING_FAILED_TICKS_IN_A_TURN}
     * earlier failed ticks) whatever the plan shows — plan tools are repeat-guard-exempt, so this bound is the only one
     * short of the step budget. Forcing on the FIRST redundant tick while open items remain is the announce-instead-of-do
     * defect, and forcing on a short loop while a `← next` item is workable takes the very tools that item needs
     * (a run with a seeded plan that could not call its handover).
     */
    public function requiresToolLessAnswerStep(int $openItemCount, int $earlierFailedTickCount, bool $planHasNextMarkedItem): bool
    {
        return $this->isAllAlreadyInStatus()
            && ($openItemCount === 0
                || ($earlierFailedTickCount > 0 && !$planHasNextMarkedItem)
                || $earlierFailedTickCount >= self::LOOPING_FAILED_TICKS_IN_A_TURN);
    }

    /** The call failed: nothing applied or noted, and no update named an item the platform had just closed. */
    public function failsTheCall(): bool
    {
        return $this->updatedCount === 0 && $this->notedCount === 0 && $this->notedObstacleCount === 0
            && !$this->isOnlyResolvedByPlatformInThisStep();
    }

    /**
     * The call's result text: the applied count with the plan's counts after the call (`$taskPlanItems`), the noted ticks, the no-change prefix, or `No item updated:` — then the feedback lines.
     */
    public function buildResultText(?TaskPlanItems $taskPlanItems): string
    {
        $tickFeedbackSentence = $this->feedback !== [] ? (' ' . implode(' ', $this->feedback)) : '';
        if ($this->updatedCount > 0) {
            $statusCounts = $taskPlanItems?->countItemsByStatus() ?? new TaskPlanItemStatusCounts();
            return sprintf(
                'Updated %d item(s). Plan now: %d done, %d open, %d blocked.',
                $this->updatedCount,
                $statusCounts->done,
                $statusCounts->open,
                $statusCounts->blocked
            ) . $tickFeedbackSentence;
        }
        if ($this->notedCount > 0 || $this->notedObstacleCount > 0) {
            // Ticks the record could not confirm: nothing failed — the item stays open and the platform
            // closes it from the record; the call never counts as a rejected plan-tool call.
            return sprintf('%d tick(s) not applied — the record closes these items; continue with the open items.', $this->notedCount + $this->notedObstacleCount)
                . $tickFeedbackSentence;
        }
        if ($this->isOnlyResolvedByPlatformInThisStep()) {
            // The model sent the tick together with the call whose record resolved the item: no failure.
            return SetTaskStatusTool::RESULT_PREFIX_NO_CHANGE_NEEDED . $tickFeedbackSentence;
        }
        return 'No item updated:' . $tickFeedbackSentence;
    }

    protected function countResolution(): void
    {
        $this->updatedCount++;
        $this->resolvedCount++;
    }
}
