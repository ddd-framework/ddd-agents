<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The result of appending ONE agent turn to a conversation ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::appendTurn()}),
 * read by the loop ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::runConversationLoop()}) to
 * decide whether to continue, end, or park — replaces the untyped `['isFinal' => …, 'completed' => …]` array that flowed
 * between the two (same rationale as {@see ModelResponse} replacing performTurn's return array).
 *
 * ValueObject, not Entity: no identity, never persisted (same as {@see ModelResponse} / {@see AgentModelInput}).
 *
 * @phpstan-consistent-constructor
 */
class AgentTurnOutcome extends ValueObject
{
    /** The model gave its final text answer (no tool calls) → the loop ends. */
    public bool $isFinal = false;

    /** A final answer with non-empty text was produced (only meaningful when {@see self::$isFinal}). */
    public bool $completed = false;

    /** The model kept re-issuing one identical tool call → the loop aborts the stall instead of burning its budget. */
    public bool $repeatedToolCallAbort = false;

    /** This turn dispatched ASYNC delegation children → the loop parks until they fold back via the fan-in. */
    public bool $awaitingDelegates = false;

    /**
     * The loop's NEXT step must run with tool_choice='none' so the model writes its closing answer instead of
     * re-calling. Armed by every stall-breaker class: a refused WRITE (identical-write repeat / third identical
     * call / widget-gate rejection), a refused duplicate READ (the answer already sits in the context), and an
     * all-no-op plan tick (every update ALREADY in that status). This flag is HOW the breakers reach the loop —
     * dispatchToolCall's assignment was method-local before (silent no-op; the loop ran straight through the
     * refusals).
     */
    public bool $forceToolLessAnswerStep = false;

    /**
     * A completed `wait_for_sub_agents` call of this step ended the turn without an assistant text: the
     * loop stops after the step; the next run end's notification resumes the conversation.
     */
    public bool $endsTurnWithoutText = false;

    public function __construct(
        bool $isFinal = false,
        bool $completed = false,
        bool $repeatedToolCallAbort = false,
        bool $awaitingDelegates = false,
        bool $forceToolLessAnswerStep = false,
    ) {
        parent::__construct();
        $this->isFinal = $isFinal;
        $this->completed = $completed;
        $this->repeatedToolCallAbort = $repeatedToolCallAbort;
        $this->awaitingDelegates = $awaitingDelegates;
        $this->forceToolLessAnswerStep = $forceToolLessAnswerStep;
    }

    /** The model produced its final text answer (no tool calls) — the loop ends. `$completed` = the text was non-empty. */
    public static function finalAnswer(bool $completed): static
    {
        return new static(isFinal: true, completed: $completed);
    }

    /**
     * The model issued tool calls — the loop continues, UNLESS a repeated-call abort tripped or this turn dispatched
     * async delegates (then the loop parks/aborts on those flags instead).
     */
    public static function toolCallsIssued(
        bool $repeatedToolCallAbort,
        bool $awaitingDelegates,
        bool $forceToolLessAnswerStep = false,
    ): static {
        return new static(
            repeatedToolCallAbort: $repeatedToolCallAbort,
            awaitingDelegates: $awaitingDelegates,
            forceToolLessAnswerStep: $forceToolLessAnswerStep,
        );
    }

    /** This outcome, with the turn ended without a text after the step ({@see $endsTurnWithoutText}). */
    public function withTurnEndWithoutText(): self
    {
        $this->endsTurnWithoutText = true;
        return $this;
    }
}
