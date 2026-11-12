<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

/**
 * What the end of one sub-agent run decided, between the run-end transaction and what follows it
 * outside: whether the child yielded to an input accepted after its last look (it continues, nothing was written),
 * whether THIS process wrote the run's end (then the notifier tells the parent), the tree whose capacity slot the child
 * freed, and the `delegate` row and run the notification is about. A child of a row stored before sub-agent records existed ends
 * through the legacy finish ({@see $legacyChildTerminal}): its batch, its slice, its parent resume. A runtime object,
 * never persisted.
 *
 * @phpstan-consistent-constructor
 */
class AgentSubAgentRunEnd
{
    /** The child yielded to an input accepted after its last look — released IDLE and resumed, no run end written. */
    public bool $yieldedForAcceptedInput = false;

    /** This process wrote the run's end (the child's terminal row and the run's terminal write committed together). */
    public bool $runEndWritten = false;

    /** The root of the tree whose delegation slot this child freed — the loop admits queued children; null = none. */
    public ?int $rootConversationIdWithFreedSlot = null;

    /** The sub-agent's `delegate` row — from the child's column, never the lazy relation. */
    public ?int $delegateCallMessageId = null;

    /** The run that ended. */
    public int $runNumber = 1;

    /** The finish of a child whose `delegate` row predates sub-agent records (the legacy adapter); null for a sub-agent run. */
    public ?AgentDelegationChildTerminal $legacyChildTerminal = null;

    public static function yieldedForAcceptedInput(): static
    {
        $agentSubAgentRunEnd = new static();
        $agentSubAgentRunEnd->yieldedForAcceptedInput = true;
        return $agentSubAgentRunEnd;
    }

    /**
     * The run end was decided: written by this process (`$runEndWritten`), or found written by another one (the
     * timeout, a redelivery) — the slot is freed either way.
     */
    public static function decided(bool $runEndWritten, ?int $rootConversationIdWithFreedSlot, ?int $delegateCallMessageId, int $runNumber): static
    {
        $agentSubAgentRunEnd = new static();
        $agentSubAgentRunEnd->runEndWritten = $runEndWritten;
        $agentSubAgentRunEnd->rootConversationIdWithFreedSlot = $rootConversationIdWithFreedSlot;
        $agentSubAgentRunEnd->delegateCallMessageId = $delegateCallMessageId;
        $agentSubAgentRunEnd->runNumber = $runNumber;
        return $agentSubAgentRunEnd;
    }

    /** A legacy child's finish, carried to its second half (the legacy parent resume). */
    public static function ofLegacyChild(AgentDelegationChildTerminal $legacyChildTerminal): static
    {
        $agentSubAgentRunEnd = new static();
        $agentSubAgentRunEnd->yieldedForAcceptedInput = $legacyChildTerminal->yieldedForAcceptedInput;
        $agentSubAgentRunEnd->rootConversationIdWithFreedSlot = $legacyChildTerminal->rootConversationIdWithFreedSlot;
        $agentSubAgentRunEnd->delegateCallMessageId = $legacyChildTerminal->delegateMessageId;
        $agentSubAgentRunEnd->legacyChildTerminal = $legacyChildTerminal;
        return $agentSubAgentRunEnd;
    }

    public function isOfLegacyChild(): bool
    {
        return $this->legacyChildTerminal !== null;
    }
}
