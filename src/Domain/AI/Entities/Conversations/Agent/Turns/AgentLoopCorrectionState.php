<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentStallRecoveryDecision;
use DDD\Domain\AI\Entities\Conversations\AIConversation;

/**
 * The corrections the agent loop carries from one step of an invocation to the next
 * (formerly six locals of `runConversationLoop()`): a tool-less answer step owed by the previous step, the one-time
 * exemption of the next response from the prose stall, whether the current step is the budget closing step, the plan
 * leaves closed on the current candidate final text, and the stall recovery's memory of this owner turn (the decision,
 * the replaced last resort, the one dictated-block nudge). A plain mutable
 * runtime object, never persisted: one per loop invocation, created at the loop start.
 *
 * @phpstan-consistent-constructor
 */
class AgentLoopCorrectionState
{
    /**
     * Write-perseveration breaker: after a step whose WRITE was refused as an
     * identical repeat, the NEXT step runs with tool_choice='none' — the model must produce its closing TEXT (the answer
     * the owner is waiting for) instead of re-calling. A perseverating model repeats the write THROUGH the refusal
     * notice, the repeat guard, and the closing-move text, and the old escalation (turn ABORT) killed the whole turn
     * with an "I had to stop" to the owner. Forcing one tool-less step converts the dead end into the answer.
     */
    public bool $forceToolLessAnswerStep = false;

    /**
     * The response that follows the text move of the stall recovery (or the budget closing step) is
     * exempted ONCE from the prose-after-load-skills predicate (`hasSkillOrToolLoadCall()` stays true after a nudge);
     * empty output and a pre-call marker stay stalls.
     */
    public bool $exemptProseStallOnce = false;

    /**
     * Budget closing step: the step that runs tool-less because the turn's step budget is
     * one call from spent — its text is the owner's message, not a work step; set per step.
     */
    public bool $budgetClosingStep = false;

    /**
     * The stall-recovery decision's per-turn memory: judged once per owner turn (from the SECOND stall).
     * Kept in loop memory for the running chunk; a chunk resume reads the persisted slice (`hasEventFor()` answers
     * "decided" on a read failure — safe for "already judged", wrong for the skip, hence the slice and not the events).
     */
    public bool $stallRecoveryJudgedThisTurn = false;

    /**
     * A decision-model-forced re-drive REPLACES the ladder's own last resort for the rest of the turn, so the
     * model-call bound stays today's.
     */
    public bool $stallLadderLastResortReplaced = false;

    /**
     * The DICTATED-BLOCK-MISSING stall was nudged in this invocation — at most once per owner turn
     * ({@see AgentStallClassification}); a resumed chunk reads the nudge row after the dictating call instead.
     */
    public bool $dictatedBlockNudgeSpentThisTurn = false;

    /**
     * The DIRECT-WIDGET-MISSING stall was nudged in this invocation — at most once per owner turn
     * ({@see AgentStallClassification}); a resumed chunk reads the turn's nudge rows instead.
     */
    public bool $directWidgetNudgeSpentThisTurn = false;

    /**
     * The WIDGET-TREE-FINDINGS signal was sent in this invocation — at most once per owner turn
     * ({@see AgentStallClassification}); a resumed chunk reads the turn's signal row instead.
     */
    public bool $widgetTreeFindingsSpentThisTurn = false;

    /**
     * The CODE-OR-FORMULA-IN-PROSE stall was nudged in this invocation — at most once per owner turn
     * ({@see AgentStallClassification}); a resumed chunk reads the turn's nudge rows instead.
     */
    public bool $codeOrFormulaNudgeSpentThisTurn = false;

    /**
     * The tool the NEXT step's model call is pinned to (`tool_choice` = that function) —
     * set once by the EMAIL run's closing-call rule ({@see armForcedOwnerReportCall()}), used up by the next step.
     */
    public ?string $forcedToolNameForNextStep = null;

    /**
     * The EMAIL run's closing call was forced in this invocation — at most once: a forced step that still writes a text
     * instead of the call ends the turn as before, it is never forced again.
     */
    public bool $ownerReportCallForcedInThisInvocation = false;

    /** The final text the forced `send_owner_report` step follows — the turn's answer once the forced call settled the report. */
    public ?string $finalTextBeforeForcedOwnerReportCall = null;

    /**
     * The final text of an EMAIL run that has no `send_owner_report` call: the next step is pinned to that tool, the text
     * already written becomes the report (or the model states NOTHING_TO_REPORT). Once per invocation.
     */
    public function armForcedOwnerReportCall(string $toolName, string $finalText): void
    {
        $this->forcedToolNameForNextStep = $toolName;
        $this->ownerReportCallForcedInThisInvocation = true;
        $this->finalTextBeforeForcedOwnerReportCall = $finalText;
    }

    /** The tool this step's model call is pinned to, or null; the pin is used up by this step. */
    public function consumeForcedToolNameForNextStep(): ?string
    {
        $forcedToolName = $this->forcedToolNameForNextStep;
        $this->forcedToolNameForNextStep = null;
        return $forcedToolName;
    }

    /**
     * The state at the start of a loop invocation: nothing owed, no exemption; the stall memory read from
     * the persisted stall-recovery decision when it belongs to the current owner turn.
     */
    public static function startForInvocation(AIConversation $conversation): static
    {
        $loopCorrectionState = new static();
        $currentTurnInitiatorIndexForStallMemory = $conversation->messages->getNewestInitiatorMessageIndex();
        $persistedStallRecoveryDecision = $conversation->getAgentWorkingState()?->stallRecoveryDecision;
        $loopCorrectionState->stallRecoveryJudgedThisTurn = $persistedStallRecoveryDecision !== null
            && $currentTurnInitiatorIndexForStallMemory !== null
            && $persistedStallRecoveryDecision->ownerMessageIndex === $currentTurnInitiatorIndexForStallMemory;
        $loopCorrectionState->stallLadderLastResortReplaced = $loopCorrectionState->stallRecoveryJudgedThisTurn
            && $persistedStallRecoveryDecision->applied
            && $persistedStallRecoveryDecision->move === AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL;
        return $loopCorrectionState;
    }

    /**
     * Mark whether the step about to call the model is the budget closing step. A closing step runs tool-less and its
     * response is exempt from the prose stall once.
     */
    public function startStep(bool $budgetClosingStep): void
    {
        $this->budgetClosingStep = $budgetClosingStep;
        if ($budgetClosingStep) {
            $this->forceToolLessAnswerStep = true;
            $this->exemptProseStallOnce = true;
        }
    }

    /** Whether this step's model call runs without tools; the owed tool-less step is used up by it. */
    public function consumeToolLessAnswerStep(): bool
    {
        $toolLessAnswerStep = $this->forceToolLessAnswerStep;
        $this->forceToolLessAnswerStep = false;
        return $toolLessAnswerStep;
    }

    /** Whether the response just received is exempt from the prose stall; the exemption is used up by it. */
    public function consumeProseStallExemption(): bool
    {
        $proseStallExemptionApplies = $this->exemptProseStallOnce;
        $this->exemptProseStallOnce = false;
        return $proseStallExemptionApplies;
    }
}
