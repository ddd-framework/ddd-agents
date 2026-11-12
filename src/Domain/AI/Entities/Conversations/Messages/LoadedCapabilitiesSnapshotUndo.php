<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * Puts a message's loaded capability set back as it was before a write that rolled back
 * (replaces the admission's undo closure): the same set object restored in place to its snapshot, or no set
 * at all when the message carried none.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilitiesSnapshotUndo implements AIConversationMessageWriteUndo
{
    protected AIConversationMessage $message;

    /** The set the message held before the write; null = it held none. */
    protected ?LoadedCapabilities $loadedCapabilities = null;

    protected ?LoadedCapabilitiesSnapshot $snapshot = null;

    /** The undo for the set `$message` holds right now. */
    public static function ofMessage(AIConversationMessage $message): static
    {
        $snapshotUndo = new static();
        $snapshotUndo->message = $message;
        $snapshotUndo->loadedCapabilities = $message->getLoadedCapabilities();
        $snapshotUndo->snapshot = $snapshotUndo->loadedCapabilities?->buildSnapshot();
        return $snapshotUndo;
    }

    /** Restore the message's set to the snapshot — or take the set away when it held none. */
    public function undo(): void
    {
        if ($this->loadedCapabilities === null || $this->snapshot === null) {
            unset($this->message->loadedCapabilities);
            return;
        }
        $this->loadedCapabilities->restoreSnapshot($this->snapshot);
        $this->message->loadedCapabilities = $this->loadedCapabilities;
    }
}
