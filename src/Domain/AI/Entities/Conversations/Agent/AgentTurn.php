<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * THE correctly-cut "agent run": one user-bounded autonomous stream — from one initiator message
 * (a human's input in the root dialog, or the orchestrator's task/follow-up in a delegation child) up to, but not
 * including, the next initiator. A pure PROJECTION over a conversation's messages ({@see AIConversation::buildAgentTurns()}):
 * computed, never persisted, no id column — its identity IS the initiating message.
 *
 * Aggregates fold over the slice AND over the delegation child turns CAUSED by this turn: a child conversation
 * attributes to the parent turn containing the `delegate` call that spawned it (its `delegatedFromMessageId` anchor).
 * Child conversations are reached via the IN-MEMORY `delegatedConversations` edges only — batch-hydrate them first for
 * persisted trees (avoids N+1 loads).
 *
 * ValueObject, not Entity: no identity column, never persisted (same rationale as {@see AgentModelInput}).
 */
class AgentTurn extends ValueObject
{
    /** The initiator message that started this turn — the turn's natural identity (no id column needed). */
    public ?AIConversationMessage $initiatingMessage = null;

    /** @var AIConversationMessage[] The slice: initiator up to (excl.) the next initiator, in order. */
    public array $messages = [];

    /** @var AgentTurn[] Delegation CHILD turns caused by a message of this turn (recursive attribution). */
    public array $causedChildTurns = [];

    /** Whether this turn ran to a real final assistant answer (vs. step-budget exhaustion / abort). */
    public function completed(): bool
    {
        foreach (array_reverse($this->messages) as $message) {
            if (
                $message->isAssistantText()
                && trim((string)($message->message ?? '')) !== ''
            ) {
                return true;
            }
            if ($message->messageType === AIConversationMessage::MESSAGE_ERROR) {
                return false;
            }
        }
        return false;
    }

    /** Assistant model steps in this turn (slice only — a delegation's steps count in ITS child turns). */
    public function steps(): int
    {
        $steps = 0;
        foreach ($this->messages as $message) {
            if (
                $message->isAssistantText()
            ) {
                $steps++;
            }
        }
        return $steps;
    }

    /** Empty-turn nudges spent in this turn (SIGNAL messages with signalSource=LOOP_NUDGE in the slice). */
    public function nudgeCount(): int
    {
        $nudges = 0;
        foreach ($this->messages as $message) {
            if (
                ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL
                && ($message->signalSource ?? null) === AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE
            ) {
                $nudges++;
            }
        }
        return $nudges;
    }

    /**
     * The delegation child conversations spawned by messages of THIS turn (in-memory edges only).
     *
     * @return AIConversation[]
     */
    public function delegations(): array
    {
        $delegations = [];
        foreach ($this->messages as $message) {
            foreach ($message->getDelegatedConversationsIfLoaded() as $childConversation) {
                $delegations[] = $childConversation;
            }
        }
        return $delegations;
    }

    /** Total cost of this turn: the slice + every child turn it caused, recursively. */
    public function totalCostUsd(): float
    {
        $totalCostUsd = 0.0;
        foreach ($this->messages as $message) {
            // the row's model cost plus its tool call's execution costs (uninitialized-safe)
            $totalCostUsd += $message->getCostInUSDIncludingToolCallExecution();
        }
        foreach ($this->causedChildTurns as $childTurn) {
            $totalCostUsd += $childTurn->totalCostUsd();
        }
        return $totalCostUsd;
    }

    /** Total tokens (input + output) of this turn incl. caused child turns. */
    public function totalTokens(): int
    {
        $totalTokens = 0;
        foreach ($this->messages as $message) {
            $totalTokens += (int)($message->inputTokens ?? 0) + (int)($message->outputTokens ?? 0);
        }
        foreach ($this->causedChildTurns as $childTurn) {
            $totalTokens += $childTurn->totalTokens();
        }
        return $totalTokens;
    }

    /** Whether the given message belongs to this turn's slice. */
    public function containsMessage(AIConversationMessage $message): bool
    {
        return in_array($message, $this->messages, true);
    }
}
