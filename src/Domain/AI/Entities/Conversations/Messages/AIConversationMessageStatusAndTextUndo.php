<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * Puts a message row's status and text back as they were before a claim-fenced write that rolled back — a signal marked
 * read by a hand-over that never committed waits again, in memory as in the row
 * (replaces the undo closures). Taken BEFORE the write changes the row.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMessageStatusAndTextUndo implements AIConversationMessageWriteUndo
{
    protected AIConversationMessage $message;

    protected ?string $statusBefore = null;

    /** The text before; null when the row held none (the text is then left as it is). */
    protected ?string $textBefore = null;

    /** The undo for the status and text `$message` holds right now. */
    public static function ofMessage(AIConversationMessage $message): static
    {
        $statusAndTextUndo = new static();
        $statusAndTextUndo->message = $message;
        $statusAndTextUndo->statusBefore = $message->status ?? null;
        $statusAndTextUndo->textBefore = $message->message ?? null;
        return $statusAndTextUndo;
    }

    /** Restore the row's status and text. */
    public function undo(): void
    {
        $this->message->status = $this->statusBefore;
        if ($this->textBefore !== null) {
            $this->message->message = $this->textBefore;
        }
    }
}
