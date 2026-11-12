<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * The loaded capability set across the rows of ONE conversation's message set, as stored in the single column
 * `loadedCapabilities`. A row stores
 *   - the COMPLETE set (a checkpoint — the JSON list it always was),
 *   - only its CHANGES against the row before (a change record `{"v":2,"e":[…]}`, `e` empty when nothing changed —
 *     {@see LoadedCapabilities::asChangeRecord()}), or
 *   - NULL: the row carries no set (an owner row the API wrote, a signal written outside the turn).
 *
 * In memory a row holds either its set or — a change row loaded and not read yet — its change record; readers go through
 * {@see AIConversationMessage::getLoadedCapabilities()}, which builds the set from the row's persisted state on the
 * first read. This object owns the translations between the stored and the resolved form:
 *   - {@see hydrate()} after a load: apply each change record to the canonical entries of the row before it, starting
 *     at the checkpoint, and give the row its persisted state;
 *   - {@see buildRowEncoding()} before a write: how the row's resolved set is stored — a checkpoint or a change record
 *     against the PREVIOUS row's state as persisted;
 *   - {@see buildReEncodingsOfRowsAfter()} after an earlier row was written again while later rows were already
 *     persisted (a tool-call row finalized after its tool emitted rows): those later rows re-encoded, so each still
 *     resolves to the exact set it had at its own persist.
 * The chain writes nothing: it returns {@see LoadedCapabilityRowEncoding} objects, the messages service writes the rows.
 *
 * The persisted state of a row lives on the row itself ({@see AIConversationMessage::getPersistedLoadedCapabilitiesState()})
 * — the loop rebuilds its message set as new set objects, the rows travel; a row's index can still move while its
 * first write looks for a free index.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapabilitiesChain
{
    /** A checkpoint is written at least every this many rows that carry a set — an autonomous run's single turn stays bounded. */
    public const int MAX_ROWS_BETWEEN_CHECKPOINTS = 50;

    /** The message set whose rows this chain reads and encodes. */
    protected AIConversationMessages $messages;

    /** The chain over the rows of `$messages` ({@see AIConversationMessages::buildLoadedCapabilitiesChain()}). */
    public static function forMessages(AIConversationMessages $messages): static
    {
        $loadedCapabilitiesChain = new static();
        $loadedCapabilitiesChain->messages = $messages;
        return $loadedCapabilitiesChain;
    }

    /**
     * Whether this process can place a NEW row in the chain: every stored row it holds that carries a set has a known
     * persisted state (it was hydrated or persisted here). A process holding an unhydrated window of stored rows cannot
     * — its carried set may be outdated.
     */
    public function canPlaceNewRows(): bool
    {
        foreach ($this->messages->getElements() as $row) {
            if (isset($row->id) && isset($row->loadedCapabilities) && $row->getPersistedLoadedCapabilitiesState() === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Give every row of the message set its persisted state, starting from `$canonicalEntriesBeforeFirstRow` (the
     * resolved set of the row before the first loaded one — null when the set starts here or there is none). One pass
     * over the rows on canonical entries (arrays; each change row costs one shallow copy of the entries, no capability
     * object): a checkpoint starts the state, each change record's changes are applied to it, and the row keeps the
     * result in its persisted state — its capability objects are built only when someone reads the row
     * ({@see AIConversationMessage::getLoadedCapabilities()}).
     * A row that carries no set keeps none; a change record that cannot be resolved (no checkpoint before it) is dropped
     * from memory, never shown as a set — the row then reads like a row without a set and its stored value stays untouched.
     *
     * @param array<string, array<string, mixed>>|null $canonicalEntriesBeforeFirstRow
     */
    public function hydrate(?array $canonicalEntriesBeforeFirstRow = null): void
    {
        $state = $canonicalEntriesBeforeFirstRow;
        foreach ($this->orderedRows() as $message) {
            $stored = isset($message->loadedCapabilities) ? $message->loadedCapabilities : null;
            if ($stored === null) {
                continue;
            }
            $changes = $stored->getChangeRecord();
            if ($changes === null) {
                $state = $stored->toCanonicalEntries();
                $message->setPersistedLoadedCapabilitiesState(PersistedLoadedCapabilitiesState::ofCheckpoint($state));
                continue;
            }
            if ($state === null) {
                unset($message->loadedCapabilities);
                continue;
            }
            foreach ($changes->getChanges() as $change) {
                $state = $change->applyToCanonicalEntries($state);
            }
            $message->setPersistedLoadedCapabilitiesState(PersistedLoadedCapabilitiesState::ofChangeRow($state, $changes->getUnloadReasons(), $changes->mapToRepository()));
        }
    }

    /**
     * How `$message` is written: a change record against the previous row's persisted state, or its complete set as a
     * checkpoint. The caller applies the stored form, writes the row, restores the resolved form (always, also when the
     * write failed) and, after a write that happened, marks it persisted ({@see LoadedCapabilityRowEncoding}). A row
     * without a set, or one already holding a change record, is left alone.
     */
    public function buildRowEncoding(AIConversationMessage $message): LoadedCapabilityRowEncoding
    {
        $resolved = isset($message->loadedCapabilities) ? $message->loadedCapabilities : null;
        if ($resolved === null || $resolved->isChangeRecord()) {
            return LoadedCapabilityRowEncoding::forUnchangedRow($message);
        }
        $chainPosition = $this->buildChainPosition($message);
        $previousState = $chainPosition->getPreviousPersistedState();
        $canonical = $resolved->toCanonicalEntries();
        $unloadReasons = [];
        $storedEvents = null;
        $changeRecord = null;
        if ($previousState !== null && !$chainPosition->isCheckpointDue()) {
            $ownState = $message->getPersistedLoadedCapabilitiesState();
            $unloadReasons = array_merge($ownState?->getUnloadReasons() ?? [], $resolved->getUnloadReasons());
            $changes = LoadedCapabilityChanges::between(LoadedCapabilities::fromCanonicalEntries($previousState->getCanonicalEntries()), $resolved, $unloadReasons);
            $unloadReasons = array_intersect_key($unloadReasons, array_flip($changes->getUnloadedCapabilityNames()));
            // Self-check: the stored record, read back through JSON and applied to the previous state, must rebuild this
            // exact set — else the row is written as a checkpoint (lossless) and the mismatch is reported.
            $changeRecordCandidate = LoadedCapabilities::asChangeRecord($changes);
            if ($this->rebuildsExactly($changeRecordCandidate, $previousState->getCanonicalEntries(), $canonical)) {
                $storedEvents = $changes->mapToRepository();
                $changeRecord = $changeRecordCandidate;
            } else {
                $unloadReasons = [];
                error_log('capability change record did not rebuild its set — row ' . ($message->id ?? 'new') . ' index ' . ($message->indexInConversation ?? '-') . ' stored as a checkpoint');
            }
        }
        return LoadedCapabilityRowEncoding::forOwnWrite(
            $message,
            $resolved,
            $changeRecord,
            $storedEvents === null
                ? PersistedLoadedCapabilitiesState::ofCheckpoint($canonical)
                : PersistedLoadedCapabilitiesState::ofChangeRow($canonical, $unloadReasons, $storedEvents)
        );
    }

    /**
     * The persisted rows AFTER `$message` whose stored changes were computed against an older state of it, each
     * re-encoded against the now-persisted state of its predecessor — so it resolves to the set it had at its own
     * persist. The caller writes ONLY the capability column of each (a partial write), in order. Stops at the next
     * checkpoint; a row whose stored events are still right is not returned.
     *
     * @return LoadedCapabilityRowEncoding[]
     */
    public function buildReEncodingsOfRowsAfter(AIConversationMessage $message): array
    {
        $ownState = $message->getPersistedLoadedCapabilitiesState();
        if ($ownState === null) {
            return [];
        }
        $previousCanonical = $ownState->getCanonicalEntries();
        $reEncodings = [];
        $passedMessage = false;
        foreach ($this->orderedRows() as $row) {
            if ($row === $message) {
                $passedMessage = true;
                continue;
            }
            if (!$passedMessage) {
                continue;
            }
            $rowState = $row->getPersistedLoadedCapabilitiesState();
            if ($rowState === null) {
                continue; // carries no set, or was never persisted
            }
            if ($rowState->isCheckpoint()) {
                break;
            }
            $changes = LoadedCapabilityChanges::between(
                LoadedCapabilities::fromCanonicalEntries($previousCanonical),
                LoadedCapabilities::fromCanonicalEntries($rowState->getCanonicalEntries()),
                $rowState->getUnloadReasons()
            );
            $storedEvents = $changes->mapToRepository();
            if ($storedEvents !== $rowState->getStoredEvents() && isset($row->id)) {
                $reEncodings[] = LoadedCapabilityRowEncoding::forReEncodedRow(
                    $row,
                    $row->getLoadedCapabilities(),
                    LoadedCapabilities::asChangeRecord($changes),
                    PersistedLoadedCapabilitiesState::ofChangeRow($rowState->getCanonicalEntries(), $rowState->getUnloadReasons(), $storedEvents)
                );
            }
            $previousCanonical = $rowState->getCanonicalEntries();
        }
        return $reEncodings;
    }

    /**
     * Whether `$changeRecord`, read back from its stored JSON and applied to `$previousCanonical`, gives `$canonical`.
     *
     * @param array<string, array<string, mixed>> $previousCanonical
     * @param array<string, array<string, mixed>> $canonical
     */
    protected function rebuildsExactly(LoadedCapabilities $changeRecord, array $previousCanonical, array $canonical): bool
    {
        $readBack = new LoadedCapabilities();
        $readBack->mapFromRepository((string)$changeRecord->mapToRepository());
        $readBackChanges = $readBack->getChangeRecord();
        return $readBackChanges !== null
            && $readBackChanges->applyTo(LoadedCapabilities::fromCanonicalEntries($previousCanonical))->toCanonicalEntries() === $canonical;
    }

    /**
     * The checkpoint rule, shared by the live writer and the migration of legacy rows: a row is a checkpoint when no
     * earlier row carries a set, when a turn initiator lies between it and that row (one checkpoint per turn), or when
     * the last checkpoint is {@see MAX_ROWS_BETWEEN_CHECKPOINTS} set-carrying rows back.
     */
    public static function isCheckpointDue(bool $hasPreviousState, bool $turnInitiatorInBetween, int $changeRowsSinceCheckpoint): bool
    {
        return !$hasPreviousState || $turnInitiatorInBetween || $changeRowsSinceCheckpoint + 1 >= self::MAX_ROWS_BETWEEN_CHECKPOINTS;
    }

    /**
     * The stored rows of ONE conversation, in index order, re-encoded into the chain format (the migration
     * of legacy rows, which each carry their complete set). Applies the same checkpoint rule as the live writer; a row
     * becomes a change row only when its change record, through a JSON round trip, rebuilds exactly its complete set —
     * else it stays a checkpoint. Rows already stored as change records are applied, never rewritten.
     *
     * @param LoadedCapabilitiesStoredRow[] $storedRows
     * @return LoadedCapabilitiesRowRewrite[] the rows to rewrite, each with its change record
     */
    public static function compactStoredRows(array $storedRows): array
    {
        $rowsToWrite = [];
        $previousCanonical = null;
        $changeRowsSinceCheckpoint = 0;
        $turnInitiatorInBetween = false;
        foreach ($storedRows as $storedRow) {
            if ($storedRow->isOwnerOrLegacyUserRoleRow) {
                $turnInitiatorInBetween = true;
            }
            if ($storedRow->loadedCapabilities === null) {
                continue;
            }
            $stored = new LoadedCapabilities();
            $stored->mapFromRepository($storedRow->loadedCapabilities);
            $storedChanges = $stored->getChangeRecord();
            if ($storedChanges !== null) {
                if ($previousCanonical !== null) {
                    $previousCanonical = $storedChanges->applyTo(LoadedCapabilities::fromCanonicalEntries($previousCanonical))->toCanonicalEntries();
                    $changeRowsSinceCheckpoint++;
                    $turnInitiatorInBetween = false;
                }
                continue;
            }
            $canonical = $stored->toCanonicalEntries();
            $isCheckpoint = self::isCheckpointDue($previousCanonical !== null, $turnInitiatorInBetween, $changeRowsSinceCheckpoint);
            if (!$isCheckpoint) {
                $encodedChangeRecord = (string)LoadedCapabilities::asChangeRecord(
                    LoadedCapabilityChanges::between(LoadedCapabilities::fromCanonicalEntries($previousCanonical), $stored)
                )->mapToRepository();
                $decoded = new LoadedCapabilities();
                $decoded->mapFromRepository($encodedChangeRecord);
                $decodedChanges = $decoded->getChangeRecord();
                if ($decodedChanges !== null
                    && $decodedChanges->applyTo(LoadedCapabilities::fromCanonicalEntries($previousCanonical))->toCanonicalEntries() === $canonical) {
                    $rowsToWrite[] = LoadedCapabilitiesRowRewrite::create($storedRow->id, $encodedChangeRecord);
                    $changeRowsSinceCheckpoint++;
                } else {
                    $isCheckpoint = true;
                }
            }
            if ($isCheckpoint) {
                $changeRowsSinceCheckpoint = 0;
            }
            $previousCanonical = $canonical;
            $turnInitiatorInBetween = false;
        }
        return $rowsToWrite;
    }

    /**
     * The persisted state of the nearest earlier row that carries a set, and whether `$message` must be a checkpoint
     * anyway ({@see isCheckpointDue()}). A row without an index has no previous state and is no due checkpoint (its
     * write then stores the complete set — the encoder only diffs against a previous state).
     */
    protected function buildChainPosition(AIConversationMessage $message): LoadedCapabilitiesChainPosition
    {
        $ownIndex = $message->indexInConversation ?? null;
        if ($ownIndex === null) {
            return LoadedCapabilitiesChainPosition::forPreviousPersistedState(null, false);
        }
        $previousState = null;
        $changeRowsSinceCheckpoint = 0;
        $turnInitiatorInBetween = $message->isOwnerOrLegacyUserRoleRow();
        $earlierRows = array_reverse(array_filter(
            $this->orderedRows(),
            static fn (AIConversationMessage $row): bool => $row !== $message && ($row->indexInConversation ?? PHP_INT_MAX) < $ownIndex
        ));
        foreach ($earlierRows as $row) {
            $rowState = $row->getPersistedLoadedCapabilitiesState();
            if ($rowState === null) {
                if ($previousState === null && $row->isOwnerOrLegacyUserRoleRow()) {
                    $turnInitiatorInBetween = true;
                }
                continue;
            }
            $previousState ??= $rowState;
            if ($rowState->isCheckpoint()) {
                break;
            }
            $changeRowsSinceCheckpoint++;
        }
        return LoadedCapabilitiesChainPosition::forPreviousPersistedState(
            $previousState,
            self::isCheckpointDue($previousState !== null, $turnInitiatorInBetween, $changeRowsSinceCheckpoint)
        );
    }

    /** @return AIConversationMessage[] the rows by index, rows without an index last in set order */
    protected function orderedRows(): array
    {
        $rows = $this->messages->getElements();
        usort($rows, static fn (AIConversationMessage $a, AIConversationMessage $b): int => ($a->indexInConversation ?? PHP_INT_MAX) <=> ($b->indexInConversation ?? PHP_INT_MAX));
        return $rows;
    }
}
