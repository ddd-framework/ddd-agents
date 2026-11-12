<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

/**
 * What the work after one dispatched tool call of a step left for the step
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentCompletedToolCallFollowUpsService::runAfterStepWorkOfToolCall()}):
 * whether the turn ends after the step without a text (`wait_for_sub_agents`), whether the call started awaited
 * delegation children, and the milliseconds of the post-write re-sweep and the plan note when a completed call ran them
 * (the call row's loop overhead). A plain runtime object, never persisted.
 */
class AgentToolCallFollowUpsOutcome
{
    /** The turn ends after this step without a text. */
    public bool $endsTurnWithoutText = false;

    /** The call's result started delegation children the turn awaits. */
    public bool $awaitingDelegates = false;

    /** The post-write re-sweep and the variant re-resolution, in milliseconds (null: the call was no completed work call). */
    public ?int $reSweepMs = null;

    /** The plan note of the completed call, in milliseconds (null: the call was no completed work call). */
    public ?int $planNoteMs = null;
}
