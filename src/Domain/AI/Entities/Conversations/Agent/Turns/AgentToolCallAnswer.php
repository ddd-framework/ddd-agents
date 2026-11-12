<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one dispatched tool call asks of the loop (formerly three by-ref flags of
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService::dispatchToolCall()}): the loop ORs
 * them into its turn accumulators — a call only ever raises a flag, never lowers one.
 *
 * @phpstan-consistent-constructor
 */
class AgentToolCallAnswer extends ValueObject
{
    /** The model re-issued one identical call past the repeat guard's limit: the turn ends. */
    public bool $repeatedToolCallAbort = false;

    /** The call dispatched ASYNC delegation children: the turn parks until they settle. */
    public bool $awaitingDelegates = false;

    /** The next step must run without tools (the breaker the loop reads at the step boundary). */
    public bool $forceToolLessAnswerStep = false;

    public static function withFlags(bool $repeatedToolCallAbort, bool $awaitingDelegates, bool $forceToolLessAnswerStep): static
    {
        $agentToolCallAnswer = new static();
        $agentToolCallAnswer->repeatedToolCallAbort = $repeatedToolCallAbort;
        $agentToolCallAnswer->awaitingDelegates = $awaitingDelegates;
        $agentToolCallAnswer->forceToolLessAnswerStep = $forceToolLessAnswerStep;
        return $agentToolCallAnswer;
    }
}
