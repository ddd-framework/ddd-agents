<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * Puts a message set back to the write state it had when a claim-fenced write began, when that write rolls back.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMessagesWriteStateUndo implements AIConversationMessageWriteUndo
{
    protected AIConversationMessages $messages;

    protected AIConversationMessagesWriteState $writeState;

    /** The undo for the write state `$messages` is in right now. */
    public static function ofMessages(AIConversationMessages $messages): static
    {
        $writeStateUndo = new static();
        $writeStateUndo->messages = $messages;
        $writeStateUndo->writeState = $messages->buildWriteState();
        return $writeStateUndo;
    }

    /** Restore the message set to the write state. */
    public function undo(): void
    {
        $this->messages->restoreWriteState($this->writeState);
    }
}
