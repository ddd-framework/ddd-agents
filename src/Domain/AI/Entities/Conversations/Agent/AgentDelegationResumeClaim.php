<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a delegation child's settle transaction decided (formerly four by-reference bools plus a
 * bool return of {@see \DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService::claimParentResumeOnChildTerminal()}).
 * Read by the child's terminal record: whether the child's terminal write won, whether it yielded to an accepted owner
 * input, whether it was already terminal, whether it claimed the parent's single resume, and whether that was the
 * batch's first settle.
 *
 * @phpstan-consistent-constructor
 */
class AgentDelegationResumeClaim extends ValueObject
{
    /** This finish claimed the batch's single parent resume (a fresh settle, or a re-wake for a late COMPLETED verdict). */
    public bool $wonParentResume = false;

    /** The child's token-guarded terminal write won — this process recorded the verdict. */
    public bool $childTerminalWon = false;

    /** The write was refused by the accepted-input fence: an owner input waits, the child continues. */
    public bool $yieldedForAcceptedInput = false;

    /** The write lost because the child was already terminal (a timeout / a cancellation cleared the token first). */
    public bool $childWasAlreadyTerminal = false;

    /** The batch settled for the first time with this finish. */
    public bool $wasFreshSettle = false;

    /** Nothing was decided: no batch, no child id, or the batch row is gone. */
    public static function nothingDecided(): static
    {
        return new static();
    }

    /** The child's terminal write lost — to the fence, to an earlier terminal state, or to a live worker. */
    public static function childTerminalLost(bool $yieldedForAcceptedInput, bool $childWasAlreadyTerminal): static
    {
        $agentDelegationResumeClaim = new static();
        $agentDelegationResumeClaim->yieldedForAcceptedInput = $yieldedForAcceptedInput;
        $agentDelegationResumeClaim->childWasAlreadyTerminal = $childWasAlreadyTerminal;
        return $agentDelegationResumeClaim;
    }

    /** The child's verdict was recorded on a batch that had settled already; a late COMPLETED re-wakes the parent. */
    public static function recordedAfterTheSettle(bool $reWakesTheParent): static
    {
        $agentDelegationResumeClaim = new static();
        $agentDelegationResumeClaim->childTerminalWon = true;
        $agentDelegationResumeClaim->wonParentResume = $reWakesTheParent;
        return $agentDelegationResumeClaim;
    }

    /** The child's verdict was recorded on an open batch; it settled now when the resume condition is met. */
    public static function recordedOnTheOpenBatch(bool $settledNow): static
    {
        $agentDelegationResumeClaim = new static();
        $agentDelegationResumeClaim->childTerminalWon = true;
        $agentDelegationResumeClaim->wonParentResume = $settledNow;
        $agentDelegationResumeClaim->wasFreshSettle = $settledNow;
        return $agentDelegationResumeClaim;
    }
}
