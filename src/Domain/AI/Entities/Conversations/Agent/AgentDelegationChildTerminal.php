<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;

/**
 * What a delegation child's terminal record decided, between its two halves: whether the child
 * yielded to an accepted owner input (it continues, nothing was published), the tree whose capacity slot it freed (the
 * loop promotes the queue), and what the parent resume needs. A runtime object, never persisted.
 */
class AgentDelegationChildTerminal
{
    /** The child yielded to an owner input accepted after its last look — released IDLE and resumed, no verdict published. */
    public bool $yieldedForAcceptedInput = false;

    /** The root of the tree whose delegation slot this child freed — the loop admits queued children; null = none. */
    public ?int $rootConversationIdWithFreedSlot = null;

    /** This child's finish claimed the batch's single parent resume. */
    public bool $wonParentResume = false;

    /** This finish settled the batch for the first time (a re-wake for a late verdict is not fresh). */
    public bool $wasFreshSettle = false;

    public ?int $delegationBatchId = null;

    /** The parent's `delegate` call row — from the child's column, never the lazy relation. */
    public ?int $delegateMessageId = null;

    /** The `delegate` call row when the child had it loaded (marked COMPLETED in memory on the fresh settle). */
    public ?AIConversationMessage $delegateCallMessage = null;
}
