<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;

/**
 * What the stall recovery of one loop step left for the loop
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentStallRecoveryService::recoverStalledStep()}): the step's
 * response (the forced re-drive's when one ran), its model, whether that response calls no tool, and whether a nudge was
 * appended — then the loop goes on to its next step. A plain runtime object, never persisted.
 *
 * @phpstan-consistent-constructor
 */
class AgentStallRecoveryOutcome
{
    /** The step's response: the stalled one, or the forced re-drive's. */
    public ModelResponse $modelResponse;

    /** The model of the step's response (the stalled call's when the re-drive names none). */
    public ?string $modelName = null;

    /** Whether the step's response calls no tool. */
    public bool $noToolCall = true;

    /** Whether a LOOP_NUDGE signal was appended — the loop continues with its next step. */
    public bool $nudgeAppended = false;

    /** The recovery appended a nudge: the loop continues with its next step. */
    public static function afterNudge(ModelResponse $modelResponse, ?string $modelName): static
    {
        $agentStallRecoveryOutcome = new static();
        $agentStallRecoveryOutcome->modelResponse = $modelResponse;
        $agentStallRecoveryOutcome->modelName = $modelName;
        $agentStallRecoveryOutcome->noToolCall = true;
        $agentStallRecoveryOutcome->nudgeAppended = true;
        return $agentStallRecoveryOutcome;
    }

    /** No nudge: the step goes on with this response (a productive forced re-drive, or a stall past the nudge budget). */
    public static function withoutNudge(ModelResponse $modelResponse, ?string $modelName, bool $noToolCall): static
    {
        $agentStallRecoveryOutcome = new static();
        $agentStallRecoveryOutcome->modelResponse = $modelResponse;
        $agentStallRecoveryOutcome->modelName = $modelName;
        $agentStallRecoveryOutcome->noToolCall = $noToolCall;
        return $agentStallRecoveryOutcome;
    }
}
