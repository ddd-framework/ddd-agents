<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * ONE row's capability column prepared for a write through the chain (the chain
 * returns the rows to write instead of a restore callable, and takes no write callback). Built by
 * {@see LoadedCapabilitiesChain::buildRowEncoding()} / {@see LoadedCapabilitiesChain::buildReEncodingsOfRowsAfter()};
 * the SERVICE writes the row: {@see applyStoredForm()} → write → {@see restoreResolvedForm()} (always, also when the
 * write failed) → after a write that happened {@see markPersisted()} and queue {@see buildUnloadReasonsUndo()}.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilityRowEncoding
{
    protected AIConversationMessage $message;

    /** Whether the write changes the row's capability column (false: the row carries no set, or a change record already). */
    protected bool $rewritesCapabilityColumn = false;

    /** The set the row holds in memory before and after the write (null: the row holds none in memory). */
    protected ?LoadedCapabilities $resolvedSet = null;

    /** What the column receives instead of the resolved set — a change record; null keeps the complete set (a checkpoint). */
    protected ?LoadedCapabilities $storedForm = null;

    /**
     * The row's persisted state once written ({@see AIConversationMessage::getPersistedLoadedCapabilitiesState()}).
     */
    protected ?PersistedLoadedCapabilitiesState $persistedStateAfterWrite = null;

    /**
     * The unload reasons the row's OWN change record took into its events: the resolved set forgets them once the row is
     * written, and gets them back when the surrounding transaction rolls back. A re-encode takes none.
     *
     * @var array<string, string>
     */
    protected array $unloadReasonsTaken = [];

    /** A row whose capability column the write leaves as the row holds it. */
    public static function forUnchangedRow(AIConversationMessage $message): static
    {
        $rowEncoding = new static();
        $rowEncoding->message = $message;
        return $rowEncoding;
    }

    /** A row's own write; `$changeRecord` null stores the resolved set as a checkpoint. */
    public static function forOwnWrite(AIConversationMessage $message, LoadedCapabilities $resolvedSet, ?LoadedCapabilities $changeRecord, PersistedLoadedCapabilitiesState $persistedStateAfterWrite): static
    {
        $rowEncoding = new static();
        $rowEncoding->message = $message;
        $rowEncoding->rewritesCapabilityColumn = true;
        $rowEncoding->resolvedSet = $resolvedSet;
        $rowEncoding->storedForm = $changeRecord;
        $rowEncoding->persistedStateAfterWrite = $persistedStateAfterWrite;
        $rowEncoding->unloadReasonsTaken = $persistedStateAfterWrite->getUnloadReasons();
        return $rowEncoding;
    }

    /** A later row re-encoded against the now-persisted state of its predecessor. */
    public static function forReEncodedRow(AIConversationMessage $message, ?LoadedCapabilities $resolvedSet, LoadedCapabilities $changeRecord, PersistedLoadedCapabilitiesState $persistedStateAfterWrite): static
    {
        $rowEncoding = new static();
        $rowEncoding->message = $message;
        $rowEncoding->rewritesCapabilityColumn = true;
        $rowEncoding->resolvedSet = $resolvedSet;
        $rowEncoding->storedForm = $changeRecord;
        $rowEncoding->persistedStateAfterWrite = $persistedStateAfterWrite;
        return $rowEncoding;
    }

    public function getMessage(): AIConversationMessage
    {
        return $this->message;
    }

    /** Put the stored form onto the row for the write (a checkpoint keeps the complete set it holds). */
    public function applyStoredForm(): void
    {
        if ($this->storedForm !== null) {
            $this->message->loadedCapabilities = $this->storedForm;
        }
    }

    /** Put the resolved set back onto the row after the write. */
    public function restoreResolvedForm(): void
    {
        if (!$this->rewritesCapabilityColumn) {
            return;
        }
        if ($this->resolvedSet !== null) {
            $this->message->loadedCapabilities = $this->resolvedSet;
        } else {
            unset($this->message->loadedCapabilities);
        }
    }

    /** After a write that happened: the resolved set forgets the reasons its record took; the row remembers its state. */
    public function markPersisted(): void
    {
        if (!$this->rewritesCapabilityColumn) {
            return;
        }
        if ($this->resolvedSet !== null && $this->unloadReasonsTaken !== []) {
            $this->resolvedSet->forgetUnloadReasons(...array_map('strval', array_keys($this->unloadReasonsTaken)));
        }
        $this->message->setPersistedLoadedCapabilitiesState($this->persistedStateAfterWrite);
    }

    /** The undo of {@see markPersisted()}'s forgetting, for a transaction that may still roll back (null: nothing forgotten). */
    public function buildUnloadReasonsUndo(): ?LoadedCapabilitiesUnloadReasonsUndo
    {
        if (!$this->rewritesCapabilityColumn || $this->resolvedSet === null || $this->unloadReasonsTaken === []) {
            return null;
        }
        return LoadedCapabilitiesUnloadReasonsUndo::forUnloadReasons($this->resolvedSet, $this->unloadReasonsTaken);
    }
}
