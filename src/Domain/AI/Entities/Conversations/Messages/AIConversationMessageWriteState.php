<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * ONE row's part of a message set's write state: its id, its index and its
 * persisted capability state when the claim-fenced write began. {@see AIConversationMessage::restoreWriteState()} puts
 * them back after a rollback.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMessageWriteState
{
    protected AIConversationMessage $message;

    protected int|string|null $id = null;

    protected ?int $indexInConversation = null;

    protected ?PersistedLoadedCapabilitiesState $persistedLoadedCapabilitiesState = null;

    /** The write state `$message` is in right now. */
    public static function ofMessage(AIConversationMessage $message): static
    {
        $messageWriteState = new static();
        $messageWriteState->message = $message;
        $messageWriteState->id = isset($message->id) ? $message->id : null;
        $messageWriteState->indexInConversation = isset($message->indexInConversation) ? $message->indexInConversation : null;
        $messageWriteState->persistedLoadedCapabilitiesState = $message->getPersistedLoadedCapabilitiesState();
        return $messageWriteState;
    }

    public function getMessage(): AIConversationMessage
    {
        return $this->message;
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getIndexInConversation(): ?int
    {
        return $this->indexInConversation;
    }

    public function getPersistedLoadedCapabilitiesState(): ?PersistedLoadedCapabilitiesState
    {
        return $this->persistedLoadedCapabilitiesState;
    }
}
