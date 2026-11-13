# Message writes

**Reader:** host developer and package maintainer

Contents: 1. Why writes are special · 2. The message-index lock · 3. The claim-fenced write · 4. Publications and
undos · 5. The free index and the strict insert · 6. Which writer to use · 7. The worker reset · 8. Rules for new
writers

## 1. Why writes are special

Rows of one conversation carry a unique `(aiConversationId, indexInConversation)`. While a worker writes its turn, the
owner's messages and clicks arrive through the API, and both sides allocate "newest + 1". A worker whose claim was
stolen must not append into the new owner's turn. And a step writes rows and columns that belong together (a row plus
the capability-chain re-encode of the rows after it; a signal hand-over plus its replay rows) — all or nothing.

Three mechanisms answer this: a named lock per conversation, a strict INSERT that refuses a taken index, and a
transaction that re-checks the run token under a row lock.

## 2. The message-index lock

`AIConversationMessagesService::lockMessageIndex($conversation)` returns an `AIConversationMessageIndexLock`;
`unlockMessageIndex()` gives it back. Always in a `finally`:

```php
$messageIndexLock = $this->lockMessageIndex($conversation);
try {
    // the write
} finally {
    $this->unlockMessageIndex($messageIndexLock);
}
```

- The name is `buildLockNameForConversation()` (one per conversation); the wait is 3 s.
- **Fail-closed:** a miss (the wait ran out, or the acquisition threw — e.g. on a connection renewed for a tool call) is
  retried once, then `MessageIndexLockUnavailableException` is thrown and nothing is written. The loop logs it and the
  turn is released by `AgentRunRelease::afterIndexLockUnavailable()` (one counted attempt, the turn left owed).
- **Re-entrant:** a process that already holds the lock gets `AIConversationMessageIndexLock::notTaken()`; only the
  hold that took the lock releases it (`isHeldInThisProcess()`).
- **A conversation without an id** takes no lock.
- **Engines:** MariaDB holds the lock on the session (GET_LOCK). PostgreSQL holds it for one transaction: a writer
  without an open transaction gets a read-committed one from the hold, and the unlock commits it.
- **A cluster with several writable nodes:** a MariaDB named lock exists only on the database node it was taken on.
  Behind a proxy that may route a new session to another node, the lock is exclusive only when the proxy routes the
  lock functions to one writer node; otherwise two writers both hold it. The strict insert of section 5 stays the
  guarantee either way: the lock only makes collisions rare, and a fenced write that fails at the insert or at the
  commit fails as a whole, never as a merge.

**Lock order:** the message-index lock first, then row locks. The fenced write, the sub-agent run-end notifier and the
hand-over of waiting signals all take the index lock before any row lock, so no lock cycle forms.

## 3. The claim-fenced write

`openClaimFencedWrite($conversation, ?string $runToken): AIConversationMessageWriteScope`, ended by exactly one of
`commitClaimFencedWrite()` or `rollBackClaimFencedWrite()` (return type `never`, it rethrows):

```php
$writeScope = $this->openClaimFencedWrite($conversation, $runToken);
try {
    // the write
} catch (\Throwable $writeError) {
    $this->rollBackClaimFencedWrite($writeScope, $writeError);
}
$this->commitClaimFencedWrite($writeScope);
```

**Open:** the outermost write forgets stale undos and queues the restore of the conversation's loaded message set;
the index lock; `beginTransaction()` (a begin that throws closes the connection, so the nesting level of DBAL is reset);
a transaction left rollback-only is restarted clean; with a token, a locking read of `runToken` on the conversation
row — a different token throws `AgentRunClaimLostException`. The row stays locked until the write ends, so a claim
steal waits for it. A null token (console, evaluation, a sync admin drive, an outsider) skips only that check.

**Nested writes** join the outer lock and transaction (`AIConversationMessageWriteScope::isAnyWriteOpen()`). DBAL
opens no savepoint: a failure inside a nested write makes the whole outer write roll back.

**Commit:** commit, mark ended, give the lock back; the outermost write then drops its undos and runs the queued
publications — after the commit and after the lock is released, each on its own (a publication that throws is logged,
never fails the committed write). A commit that throws is rolled back.

**Rollback:** roll back (a rollback that cannot reach the server closes the connection; after a failed COMMIT the
outermost write closes the connection to end a server transaction that would keep the row locked), mark ended, give the
lock back; the outermost write drops its publications and runs the undos newest first, then rethrows.

**Unfenced:** a conversation without an id gets `AIConversationMessageWriteScope::unfenced()`; commit and rollback do
nothing.

`AIConversationMessagesService::isClaimFencedWriteOpen()` is the public question; a database error inside an open write
must fail the write, never be swallowed.

## 4. Publications and undos

- `publishAfterCommit(AIConversationMessageWritePublication)`: inside an open write it is queued for the outermost
  commit, outside one it runs at once. The publication of a written row is `AIConversationWrittenMessagePublication`
  (the recall embedding, the console line, the realtime tick through
  `AIConversationMessagePublicationService::publishWrittenMessage()`). The embedding worker
  (`AIConversationMessageEmbeddingHandler`) skips a job whose row it cannot find yet — so nothing is dispatched before the commit.
- `undoOnRollback(AIConversationMessageWriteUndo)`: an in-memory change the write made that must be reverted if it
  rolls back. Implementations: `AIConversationMessagesWriteStateUndo` (the loaded message set),
  `AIConversationMessageStatusAndTextUndo` (a signal marked read), `LoadedCapabilitiesSnapshotUndo`,
  `LoadedCapabilitiesUnloadReasonsUndo`, `ToolCallInjectionScreeningUndo`. Outside an open write an undo is dropped.

The process state every open write shares is static on `AIConversationMessageWriteScope` (open depth, queued
publications, queued undos, message sets with a restore queued); `forgetOpenWritesOfThisProcess()` clears it.

## 5. The free index and the strict insert

An existing row is written again with `update(0)`. A new row is a strict `insert(0)` (`writeMessageRow()`): an index
another writer took throws `UniqueConstraintViolationException` instead of merging two rows into one.

`writeNewRowAtFreeIndex()` (under the index lock): the row keeps the index allocated in memory when it is still free
and the in-memory view reaches the persisted newest index (`findNewestMessageIndex()`); otherwise it is re-anchored
above the persisted newest and the loaded set is re-sorted. A free lower index is kept on purpose: the call rows of
one step must stay contiguous with the step's head even when a call wrote a row above them while it ran — otherwise the
provider rejects the next request.

A refused insert is written again above the larger of the persisted newest index (a plain `findNewestMessageIndex()`)
and the refused index, up to `NEW_ROW_INDEX_COLLISION_ATTEMPTS`. No locking read is made: the winner committed after
this transaction's snapshot and is invisible to it, so the refused index itself is the anchor; a locking read of the
winner's row would fail on MariaDB with error 1020 ("record has changed since last read") under snapshot isolation.
On MariaDB the refused statement alone is undone and the transaction stays usable for the retry. On PostgreSQL a refused insert ends the transaction, so
there is no second attempt (`canWriteAgainAfterARefusedInsert()`); its exclusive lock and read-committed writers make
the collision unexpected there. A collision that survives every attempt under a claim is turned into
`AgentRunClaimLostException` by `persistMessageUnderRun()` (a concurrent drive); without a claim it is rethrown as the
bug it is.

The new row's capability set is stored as a checkpoint or as its changes against the chain; a row written from a stale
view drops the set it carried (`dropCarriedLoadedCapabilities()`) so it never hides newer changes.

## 6. Which writer to use

| Situation | Writer | Fence |
|---|---|---|
| A row of the turn this process drives (the loop's rows, a sub-agent spawn row) | `persistMessageUnderRun(AgentRun, …)` | claim-fenced with the process's token; new rows at a free index; a re-write re-encodes later rows |
| A new row of the driven turn without an `AgentRun` (a tool's widget row) | `persistNewRowUnderRunClaim()` | claim-fenced |
| An inbound row from a process that does not drive the turn (owner messages and clicks, a host's notice) | `persistInboundRowIndexSafe()`, or `appendInboundSignal()` for a signal | index lock only |
| A signal a running turn takes at its next model call | `persistSignalWaitingForRunningTurn()` (through `appendInboundSignal()` for a source that a running turn picks up) | index lock; written WAITING; with a live claim a waiting row of the same source takes the new text (owner-authored: only an identical text) |
| Only the capability column of an existing row | `persistLoadedCapabilitiesUnderRunClaim()` / `persistCapabilityChangeOfRowUnderRun()` | claim-fenced; never the whole row |
| The injection-screening verdict on a call row | `persistToolCallVerdictWhileScreeningPending()` | its own transaction, row locked, only while the row says pending |
| Soft-delete from an index (admin edit and branch) | `truncateFrom()` | index lock; sets `truncatedAt` and cascades to the children the rows spawned |
| A conversation column | `updatePartialIgnoringRights()` on the named columns, or a JSON slice | never the whole row |

From host code, the usual call is `AIConversationTurnSignalsService::injectSignal()` (inbound signal plus the right
resume decision) or `driveConversation()` (an owner message plus the drive). A widget written by a tool:
`appendWidget()` (`ddd-module-agents-widgets-specialist`).

## 7. The worker reset

A job must not inherit the previous job's claims, locks or open writes. `AgentWorkerStateResetter` (collected by the
framework's worker-state middleware) calls, only when no fenced write is open (a `sync://` job runs inside the current
one): `AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess()` and
`AIConversationMessagesService::forgetClaimFencedWriteStateForNewJob()` — which releases every index lock the process
still holds on the server (failures swallowed: the lock ends with the session), forgets the held names
(`AIConversationMessageIndexLock::forgetLocksHeldInThisProcess()`) and resets the write-scope statics.

## 8. Rules for new writers

- Inside the loop: always `persistMessageUnderRun()`.
- A writer outside the run into a conversation another process may drive is an inbound writer. Keep the list of
  such callers closed in the host's own test suite: each new caller is a decision, with its reason.
- A multi-row write that must be atomic opens one fenced write around all of it.
- Never read-modify-write a conversation row while a run may hold it: the claim columns would be overwritten.
- Dispatch nothing that reads the new row from inside an open write — queue a publication instead.
