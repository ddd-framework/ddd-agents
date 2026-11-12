<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The step and cost budget of the CURRENT owner turn — pure over the loaded conversation: its run
 * settings, its task plan, the tool-declared step floor and the turn's initiator. Re-derived at every step boundary
 * (the plan or a tool may raise it mid-turn), so it is built per question, never kept.
 *
 * Steps (plan-adaptive): an explicit {@see RunSettings::$maxStepsPerTurn} (admin task, eval
 * fixture, delegation inherit) always wins; without one, a turn whose task plan was touched THIS turn — or a work-signal
 * turn of an autonomous purpose whose plan still has open items (the re-seeded plan is deliberately unstamped) — scales
 * with the plan's leaf count (base + per item, clamped to [MAX_STEPS, ceiling]); a plan-less turn keeps the interactive
 * default. A floor a TOOL declared for this turn raises whatever the plan yields, under the same ceiling.
 *
 * Cost: an explicit {@see RunSettings::$maxTurnCostUsd} wins; every RAISED turn without one (plan, tool floor, explicit
 * steps) gets {@see PLANNED_TURN_DEFAULT_COST_CAP_USD} as the second brake; a plain interactive turn has no cap.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationTurnBudget extends ValueObject
{
    /** Safety bound on the number of model turns per user message (prevents runaway tool loops); also a worker chunk's size. */
    public const int MAX_STEPS = 12;

    /** Fixed overhead of a planned turn — plan creation, load_skills, delegate collection turns, the final synthesis. */
    public const int PLANNED_TURN_BASE_STEPS = 6;

    /** Steps per plan LEAF item — empirically one work call + its set_task_status tick + an occasional retry/second read. */
    public const int PLANNED_TURN_STEPS_PER_ITEM = 3;

    /** Hard ceiling — a plan has no item limit, so a bloated plan must not buy unbounded steps; the cost cap is the second brake. */
    public const int PLANNED_TURN_MAX_STEPS_CEILING = 60;

    /**
     * Default cost ceiling for a raised turn without an explicit maxTurnCostUsd — the raised step budget must never make
     * a premium model silently expensive. CALIBRATION 2.00: 0.50 cut a
     * LEGITIMATE analysis run right before its final synthesis (20 own steps + 4 delegation children ≈ $0.48; the
     * children's spend counts into the turn by design). The SECOND brake behind the step ceiling: it stops a runaway
     * (120 steps × ~$0.05 ≈ $6), never a wide-but-honest analysis with a delegation fan.
     */
    public const float PLANNED_TURN_DEFAULT_COST_CAP_USD = 2.00;

    /** The effective step budget of the whole owner turn, across all of its chunked worker invocations. */
    public int $totalMaxSteps = self::MAX_STEPS;

    /** The effective cost ceiling of the owner turn, null = no cap (a plain interactive turn). */
    public ?float $maxTurnCostUsd = null;

    public static function buildForConversation(AIConversation $conversation, bool $purposeWorksAutonomously): static
    {
        $turnBudget = new static();
        $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : null;
        $toolDeclaredFloor = self::getTurnMinimumStepsDeclaredForCurrentTurn($conversation);
        $taskPlanTouchedInCurrentTurn = self::isTaskPlanTouchedInCurrentTurn($conversation);

        if ($runSettings?->maxStepsPerTurn !== null) {
            // clamped here as well as at the DTO ingress — persisted/legacy run settings bypass DTO validation
            $turnBudget->totalMaxSteps = max(1, min(RunSettings::MAX_STEPS_PER_TURN_CEILING, $runSettings->maxStepsPerTurn));
        } else {
            $cappedToolFloor = min(self::PLANNED_TURN_MAX_STEPS_CEILING, $toolDeclaredFloor ?? 0);
            if (!$taskPlanTouchedInCurrentTurn && !self::workSignalTurnCarriesAnOpenPlan($conversation, $purposeWorksAutonomously)) {
                $turnBudget->totalMaxSteps = max(self::MAX_STEPS, $cappedToolFloor);
            } else {
                $planLeafCount = $conversation->getTaskPlan()?->items?->countLeafItems() ?? 0;
                $turnBudget->totalMaxSteps = max(self::MAX_STEPS, $cappedToolFloor, min(
                    self::PLANNED_TURN_MAX_STEPS_CEILING,
                    self::PLANNED_TURN_BASE_STEPS + self::PLANNED_TURN_STEPS_PER_ITEM * $planLeafCount
                ));
            }
        }

        if ($runSettings?->maxTurnCostUsd !== null) {
            $turnBudget->maxTurnCostUsd = $runSettings->maxTurnCostUsd;
        } elseif ($runSettings?->maxStepsPerTurn !== null || $taskPlanTouchedInCurrentTurn || $toolDeclaredFloor !== null) {
            $turnBudget->maxTurnCostUsd = self::PLANNED_TURN_DEFAULT_COST_CAP_USD;
        }
        return $turnBudget;
    }

    /**
     * This invocation's step limit from the turn budget: worker invocations stay MAX_STEPS-sized chunks (the yield
     * machinery carries the remainder); sync paths (CLI/eval/HTTP-sync) run the whole remainder inline.
     */
    public function deriveInvocationStepLimit(int $stepsConsumedBeforeInvocation, bool $isWorkerInvocation): int
    {
        $remainingTurnSteps = max(0, $this->totalMaxSteps - $stepsConsumedBeforeInvocation);
        return $isWorkerInvocation ? min(self::MAX_STEPS, $remainingTurnSteps) : $remainingTurnSteps;
    }

    /**
     * Whether THIS step is the turn's BUDGET CLOSING STEP (moved from the agent loop): the
     * steps consumed in the owner turn are one short of this budget, the turn has no final text (the loop asks), and no
     * closing step was granted for this owner turn yet (the working-state stamp — chunk-proof). A budget of one has no
     * closing step (nothing before it). EVAL-ONLY stage boundary: an
     * eval conversation whose run settings name a tool ends the turn through the SAME closing step once that tool
     * completed in this turn — the replay harness then injects the owner's next message between two build stages.
     * Inert on every real conversation ({@see RunSettings::evalStopAfterToolApplies()} needs the eval marker); never on
     * a delegation child (its text is a verdict to its parent, not an owner message).
     */
    public function isClosingStepDue(AIConversation $conversation): bool
    {
        $currentTurnInitiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex() ?? -1;
        $agentWorkingState = $conversation->getAgentWorkingState();
        if ($agentWorkingState?->budgetClosingStepTurnInitiatorMessageIndex === $currentTurnInitiatorMessageIndex) {
            return false; // granted once per owner turn, chunk-proof
        }
        $stepsConsumedInTurn = $conversation->messages->buildCurrentTurn()->countModelCallSteps();
        $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : null;
        $evalStopAfterToolName = $runSettings?->evalStopAfterToolName;
        if ($stepsConsumedInTurn >= 1 && $evalStopAfterToolName !== null && !$conversation->isDelegation()
            && $runSettings->evalStopAfterToolApplies($evalStopAfterToolName)
            && $conversation->messages->buildCurrentTurn()->hasCompletedToolCallNamed($evalStopAfterToolName)
        ) {
            return true;
        }
        return $stepsConsumedInTurn >= 1 && $stepsConsumedInTurn === $this->totalMaxSteps - 1;
    }

    /**
     * Whether the CURRENT turn carries the task plan — the staleness predicate the checked stop arms on: a plan exists
     * AND its stamp matches the current turn's initiator. A leftover plan from an earlier run never counts.
     */
    public static function isTaskPlanTouchedInCurrentTurn(AIConversation $conversation): bool
    {
        $taskPlan = $conversation->getTaskPlan();
        return $taskPlan !== null
            && $taskPlan->wasUpdatedInTurn($conversation->messages->getNewestInitiatorMessageIndex() ?? -1);
    }

    /**
     * A WORK-SIGNAL turn of an AUTONOMOUS purpose whose plan still has open items counts as a planned turn although the
     * plan is unstamped: the re-seeded build plan is deliberately not stamped (seed = found, not written), so until the
     * first accepted tick the turn ran on the interactive default (in one run: "Turn step budget reached" after the
     * brief, theme and plan stages, the page writes never started). The seed IS the plan of this turn.
     */
    public static function workSignalTurnCarriesAnOpenPlan(AIConversation $conversation, bool $purposeWorksAutonomously): bool
    {
        $taskPlan = $conversation->getTaskPlan();
        return $taskPlan !== null
            && $taskPlan->hasOpenItems()
            && $conversation->messages->hasWorkSignalAsNewestInboundMessage()
            && $purposeWorksAutonomously;
    }

    /**
     * The step floor a TOOL declared for the CURRENT turn (`turnMinimumSteps` on a completed tool result), or null — a
     * floor stamped for an earlier turn never counts (the same staleness rule as the plan's).
     */
    public static function getTurnMinimumStepsDeclaredForCurrentTurn(AIConversation $conversation): ?int
    {
        $agentWorkingState = $conversation->getAgentWorkingState();
        if ($agentWorkingState === null || $agentWorkingState->turnMinimumSteps === null) {
            return null;
        }
        $currentTurnInitiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex() ?? -1;
        return $agentWorkingState->turnMinimumStepsTurnInitiatorMessageIndex === $currentTurnInitiatorMessageIndex
            ? $agentWorkingState->turnMinimumSteps
            : null;
    }
}
