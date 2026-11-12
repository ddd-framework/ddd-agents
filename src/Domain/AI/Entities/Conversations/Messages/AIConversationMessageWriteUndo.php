<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * An in-memory change of an open claim-fenced message write that is put back when that write rolls back — queued through {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::undoOnRollback()},
 * held by {@see AIConversationMessageWriteScope} and run newest first after the outermost write rolled back. Taken
 * BEFORE the write changes what it restores.
 */
interface AIConversationMessageWriteUndo
{
    /** Put the in-memory state back as it was before the write. */
    public function undo(): void;
}
