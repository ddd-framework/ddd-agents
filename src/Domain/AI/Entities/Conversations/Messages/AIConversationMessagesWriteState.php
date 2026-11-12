<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * A message set's write state (a value instead of a restore closure): its
 * rows in set order, each with the id, index and persisted capability state it had when the claim-fenced write began
 * ({@see AIConversationMessageWriteState}), and the newest index. {@see AIConversationMessages::restoreWriteState()}
 * puts the set back after a rollback ({@see AIConversationMessagesWriteStateUndo}), so it describes the database again
 * — a row appended during the write leaves, a row whose id came from a rolled-back insert loses it, and the capability
 * chain forgets the states it remembered for writes that never committed.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMessagesWriteState
{
    /** @var AIConversationMessageWriteState[] in set order */
    protected array $messageWriteStates = [];

    protected ?int $lastIndexInConversation = null;

    /** The write state `$messages` is in right now. */
    public static function ofMessages(AIConversationMessages $messages): static
    {
        $writeState = new static();
        foreach ($messages->getElements() as $message) {
            $writeState->messageWriteStates[] = AIConversationMessageWriteState::ofMessage($message);
        }
        $writeState->lastIndexInConversation = $messages->lastIndexInConversation;
        return $writeState;
    }

    /** @return AIConversationMessageWriteState[] in set order */
    public function getMessageWriteStatesInSetOrder(): array
    {
        return $this->messageWriteStates;
    }

    public function getLastIndexInConversation(): ?int
    {
        return $this->lastIndexInConversation;
    }
}
