<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\HidePropertyOnSystemSerialization;

/**
 * What a {@see TaskPlanDoneCriterion} may read — the domain-free part: the conversation, its messages
 * and the LIVE plan. Built ONCE per `set_task_status` call, seed walk or re-sweep by the purpose
 * ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::buildDoneCriterionInputs()}); a purpose
 * whose criteria read more (the record of the space, gathered facts) returns its own subclass. `$taskPlanItems` is the same object
 * the update loop mutates, so a criterion that reads the plan sees it as mutated so far.
 *
 * A `set_task_status` call's inputs also answer the tick's connected-channel gate ({@see findChannelConnectionState()}):
 * a channel's live connection state is no criterion, but the purpose reads it from the space's record like one. It
 * is read when the gate asks, over the space loaded fresh at that moment (the OAuth flow writes the connection in
 * another process), and only when the record service named the purpose that reads it
 * ({@see readChannelConnectionsThrough()}); otherwise the gate is not applied.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanDoneCriterionInputs extends ValueObject
{
    /**
     * The conversation the criteria judge — always set by {@see forConversation()}: the caller's conversation, or an
     * unsaved one of the pieces a direct call passes ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::buildConversationOfTheRecordPieces()}).
     * Uninitialised only on inputs created bare (`new`, the container's no-argument constructor), which no criterion asks
     * for the conversation.
     */
    public AIConversation $conversation;

    /** The conversation's messages so far; null when the caller has none (a seed walk before the dialog). */
    public ?AIConversationMessages $conversationMessages = null;

    /** The LIVE plan; null when the conversation has none yet. */
    public ?TaskPlanItems $taskPlanItems = null;

    /** The purpose that reads a channel's connection state for the tick; null = no reader, the connected-channel gate is not applied. */
    #[HidePropertyOnSystemSerialization]
    protected ?AIConversationPurpose $channelConnectionsPurpose = null;

    /** The space whose channels {@see $channelConnectionsPurpose} reads. */
    #[HidePropertyOnSystemSerialization]
    protected ?AgentSpace $channelConnectionsAgentSpace = null;

    public static function forConversation(AIConversation $conversation, ?AIConversationMessages $conversationMessages, ?TaskPlanItems $taskPlanItems): static
    {
        $taskPlanDoneCriterionInputs = new static();
        $taskPlanDoneCriterionInputs->conversation = $conversation;
        $taskPlanDoneCriterionInputs->conversationMessages = $conversationMessages;
        $taskPlanDoneCriterionInputs->taskPlanItems = $taskPlanItems;
        return $taskPlanDoneCriterionInputs;
    }

    /**
     * The inputs of the supervisor's REPLAY of a recorded `set_task_status` call: the point-in-time messages and the
     * replayed plan over an unsaved conversation. The replay asks no criterion (it passes an empty registry) and reads no
     * channel.
     */
    public static function forReplay(AIConversationMessages $replayedMessages, ?TaskPlanItems $replayedTaskPlanItems): static
    {
        $replayedConversation = AIConversation::newInstance();
        $replayedConversation->messages = $replayedMessages;
        return static::forConversation($replayedConversation, $replayedMessages, $replayedTaskPlanItems);
    }

    /** The tick's connected-channel gate reads the channels of `$agentSpace` through `$aIConversationPurpose`. */
    public function readChannelConnectionsThrough(AIConversationPurpose $aIConversationPurpose, AgentSpace $agentSpace): void
    {
        $this->channelConnectionsPurpose = $aIConversationPurpose;
        $this->channelConnectionsAgentSpace = $agentSpace;
    }

    /** Whether the connected-channel gate has a reader ({@see readChannelConnectionsThrough()}). */
    public function readsChannelConnections(): bool
    {
        return $this->channelConnectionsPurpose !== null;
    }

    /**
     * The live connection state of `$connectedChannel`, read now: true = connected, false = readable and not connected,
     * null = unreadable or no reader (never treated as connected).
     */
    public function findChannelConnectionState(string $connectedChannel): ?bool
    {
        if ($this->channelConnectionsPurpose === null || $this->channelConnectionsAgentSpace === null) {
            return null;
        }
        return $this->channelConnectionsPurpose->findChannelConnectionStateOfAgentSpace($this->channelConnectionsAgentSpace, $connectedChannel);
    }
}
