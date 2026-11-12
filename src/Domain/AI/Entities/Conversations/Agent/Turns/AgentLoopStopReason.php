<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Why one invocation of the agent loop stopped — the loop's six local exit flags
 * as one value: {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::runConversationLoop()} sets ONE
 * reason where it leaves the step loop, and a loop that runs out of steps without one has reached its step budget.
 * Whether the invocation then YIELDS into a continuation is a second decision, made after the loop from the reason and
 * the step counts ({@see withYieldForContinuation()}), never a reason chosen inside it. The loop returns it on
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentReply::$loopStopReason}.
 *
 * @phpstan-consistent-constructor
 */
class AgentLoopStopReason extends ValueObject
{
    /** The model wrote a final text with content, or the delivered owner report is the final answer. */
    public const string ANSWERED = 'ANSWERED';

    /** The model's final step (no tool call) wrote no text: the invocation ends like a spent step budget. */
    public const string EMPTY_FINAL_TEXT = 'EMPTY_FINAL_TEXT';

    /** The step started async delegation children: the loop ends without text, the children's answers resume it. */
    public const string PARKED_FOR_DELEGATES = 'PARKED_FOR_DELEGATES';

    /**
     * A turn that sub-agent notifications started ended with `wait_for_sub_agents`: no text, no
     * terminal report; the next run end's notification resumes the conversation.
     */
    public const string WAITING_FOR_SUB_AGENTS = 'WAITING_FOR_SUB_AGENTS';

    /** The run claim was lost mid-turn (heartbeat, token-guarded append, UNIQUE collision): the loop ends silently. */
    public const string CLAIM_LOST = 'CLAIM_LOST';

    /** The repeat guard aborted the model's identical tool call. */
    public const string REPEATED_CALL_ABORTED = 'REPEATED_CALL_ABORTED';

    /** The turn's cost reached its cap at a step boundary. */
    public const string COST_CAP_REACHED = 'COST_CAP_REACHED';

    /** The budget closing step produced no owner message (no text, or a leaked tool call). */
    public const string BUDGET_CLOSING_STALLED = 'BUDGET_CLOSING_STALLED';

    /** The loop ran out of steps (the invocation's batch or the turn's total budget). */
    public const string STEP_BUDGET_REACHED = 'STEP_BUDGET_REACHED';

    /** @var string One of the constants above. */
    public string $reason = self::STEP_BUDGET_REACHED;

    /** @var bool The invocation yielded at a chunk boundary: a continuation signal trails, the next invocation continues the turn. */
    public bool $yieldedForContinuation = false;

    public static function answered(): self
    {
        return self::forReason(self::ANSWERED);
    }

    /** The model's final step: answered when its text had content, else the empty final text. */
    public static function finalText(bool $finalTextHasContent): self
    {
        return self::forReason($finalTextHasContent ? self::ANSWERED : self::EMPTY_FINAL_TEXT);
    }

    public static function parkedForDelegates(): self
    {
        return self::forReason(self::PARKED_FOR_DELEGATES);
    }

    public static function waitingForSubAgents(): self
    {
        return self::forReason(self::WAITING_FOR_SUB_AGENTS);
    }

    public static function claimLost(): self
    {
        return self::forReason(self::CLAIM_LOST);
    }

    /**
     * The claim was lost after the loop had already decided a reason: an answer stays the reason (the claim was lost in
     * the plan closings after the final text — the invocation neither yields nor gets the terminal report either way,
     * and the reply stays completed); otherwise the claim loss is the reason.
     */
    public static function claimLostAfter(?self $reasonDecidedBefore): self
    {
        return $reasonDecidedBefore !== null && $reasonDecidedBefore->isAnswered() ? $reasonDecidedBefore : self::claimLost();
    }

    public static function repeatedCallAborted(): self
    {
        return self::forReason(self::REPEATED_CALL_ABORTED);
    }

    public static function costCapReached(): self
    {
        return self::forReason(self::COST_CAP_REACHED);
    }

    public static function budgetClosingStalled(): self
    {
        return self::forReason(self::BUDGET_CLOSING_STALLED);
    }

    public static function stepBudgetReached(): self
    {
        return self::forReason(self::STEP_BUDGET_REACHED);
    }

    protected static function forReason(string $reason): static
    {
        $loopStopReason = new static();
        $loopStopReason->reason = $reason;
        return $loopStopReason;
    }

    /** The same reason, with the invocation yielded into a continuation (the second decision after the loop). */
    public function withYieldForContinuation(): self
    {
        $yieldedLoopStopReason = clone $this;
        $yieldedLoopStopReason->yieldedForContinuation = true;
        return $yieldedLoopStopReason;
    }

    /** Whether the invocation ran to a real answer ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentReply::$completed}). */
    public function isAnswered(): bool
    {
        return $this->reason === self::ANSWERED;
    }

    public function isWaitingForSubAgents(): bool
    {
        return $this->reason === self::WAITING_FOR_SUB_AGENTS;
    }

    public function isYieldedForContinuation(): bool
    {
        return $this->yieldedForContinuation;
    }

    /**
     * Whether the reason leaves the turn unfinished without ending it: the loop ran out of steps, the closing step
     * stalled, or the final text was empty. Only these may yield into a continuation — an answer, a park, a lost claim,
     * a repeat abort and the cost cap never do; the step counts decide the rest, after the loop.
     */
    public function mayYieldForContinuation(): bool
    {
        return in_array($this->reason, [self::STEP_BUDGET_REACHED, self::BUDGET_CLOSING_STALLED, self::EMPTY_FINAL_TEXT], true);
    }

    /**
     * The chunk-YIELD decision (moved from the agent loop) — the loop's four
     * non-yield exits made explicit: (1) the turn ENDED (final answer / parked / claim lost / repeat abort) and (2) the
     * COST cap broke the loop ({@see mayYieldForContinuation()}); (3) the loop broke out EARLY ($stepAtLoopExit <
     * $invocationStepLimit — the stall give-up appended an empty final answer): yielding would re-drive a stalling model
     * in a fresh invocation; (4) the TURN's total step budget is spent — the graceful step terminal follows. Only a WORKER
     * invocation ($runToken held — sync paths run the whole remainder inline and have no re-fire machinery) whose batch
     * was exhausted BY PRODUCTIVE STEPS with turn budget remaining yields.
     */
    public function shouldYieldInvocationForContinuation(
        ?string $runToken,
        int $stepAtLoopExit,
        int $invocationStepLimit,
        int $stepsConsumedInTurn,
        int $totalMaxStepsPerTurn
    ): bool {
        return $this->mayYieldForContinuation()
            && $runToken !== null
            && $stepAtLoopExit >= $invocationStepLimit
            && $stepsConsumedInTurn < $totalMaxStepsPerTurn;
    }

    /**
     * Whether the invocation ends with the terminal ERROR row and the run report: it neither answered, parked for
     * delegates, lost its claim nor yielded.
     */
    public function endsWithATerminalReport(): bool
    {
        return !in_array($this->reason, [self::ANSWERED, self::PARKED_FOR_DELEGATES, self::CLAIM_LOST, self::WAITING_FOR_SUB_AGENTS], true)
            && !$this->yieldedForContinuation;
    }

    /** The text of the terminal ERROR row ({@see endsWithATerminalReport()}). */
    public function terminalErrorText(): string
    {
        return match ($this->reason) {
            self::REPEATED_CALL_ABORTED => 'Aborted: the model repeated an identical tool call without making progress.',
            self::COST_CAP_REACHED => 'Turn cost budget (maxTurnCostUsd) reached before completion.',
            default => 'Turn step budget reached before completion.',
        };
    }

    /** The stop reason the terminal run report names ({@see endsWithATerminalReport()}). */
    public function terminalReportStopReason(): string
    {
        return match ($this->reason) {
            self::REPEATED_CALL_ABORTED => AgentRunReport::STOP_REASON_REPEATED_STEP_WITHOUT_PROGRESS,
            self::COST_CAP_REACHED => AgentRunReport::STOP_REASON_COST_BUDGET_USED_UP,
            default => AgentRunReport::STOP_REASON_STEP_BUDGET_USED_UP,
        };
    }
}
