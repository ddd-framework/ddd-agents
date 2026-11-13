# Design decisions of the loop

**Reader:** package maintainer

Contents: a sub-agent is a conversation · turns and runs are projections · the claim is a fenced UPDATE · the token
lives in the process registry · a release is one value object · a failed turn stays owed · recovery by markers and
sweeps · new rows are strict inserts under an index lock · writes are fenced transactions with publications and undos ·
loop state is derived from rows · chunked execution · task plan items carry server-minted keys · a keyed plan call is
accepted or rejected whole · the plan lives on the conversation · one checked-stop rule · overhead costs by an atomic
increment

## A sub-agent is a conversation

**Rule.** A delegation child is an `AIConversation` of its own, anchored on the `delegate` row that spawned it
(`delegatedFromMessageId`, `rootConversationId`). One conversation is one context window; the same loop runs on a root
and on a child.

**Reason.** A sub-agent has its own system prompt, history, skills and loop — structurally it is a conversation whose
owner is the orchestrating agent. As its own row it can be claimed, run by its own worker, span turn boundaries of the
parent and be continued later; the context build of every conversation is "its messages", with no partition predicate.

**Rejected.** Threads as partitions of one message list (every context build needed partition filters, ids were shared
across contexts, the service swapped run state in and out around recursion). A turn as a container that owns its
sub-threads (fits a synchronous delegation, breaks as soon as a child outlives the parent's turn and forbids multi-turn
children).

**Would change if.** Children had to share one context window with the parent (no separate prompt or history), or a
storage engine made one row per child too expensive.

## Turns and runs are projections

**Rule.** A turn is not stored: `AgentTurn` / `AIConversationTurnMessages` are projections from one initiator message to
the next; the initiator is the turn's identity. `AgentRun` is an in-memory object for one invocation of the loop, never
persisted, holding no counter that rows can answer.

**Reason.** Every count a run needs (steps, cost, bounces) is an aggregate over the rows; storing it creates a second
truth that a crash, a chunk yield or a claim steal can leave behind. The initiating message is a natural, stable
boundary marker.

**Rejected.** A persisted run entity with a run id on every message (an identity column that adds nothing the initiator
does not give); run state on the conversation or on the service instance (wrong under nested `sync://` jobs and inline
children sharing one service instance).

**Would change if.** A question about a turn could not be answered from its rows in bounded time (a turn so long that
its fold became the cost driver).

## The claim is a fenced UPDATE

**Rule.** A run is claimed by one conditional UPDATE that writes RUNNING and a fresh token; the token is re-checked
before every append, at every heartbeat and at the release. Nothing holds a transaction across a model call.

**Reason.** Duplicate resumes are normal (sibling run ends, broker redelivery, recovery passes, an owner writing during
a turn). A single affected row names one winner without a lock server, and a stale worker's writes are refused by the
token, not by timing.

**Rejected.** A transaction or row lock held for the whole turn (minutes-long locks across model and tool calls); a
named lock per conversation for the run (lost with the session, invisible to the recovery finders); trusting the
broker's exactly-once delivery.

**Would change if.** Turns became short enough to hold a lock for, or the platform moved to a queue that guarantees one
consumer per conversation.

## The token lives in the process registry

**Rule.** The token this process holds is kept in a static registry of `AIConversationRunClaimsService`, not on
`AgentRun`; the worker job reset clears it.

**Reason.** The claim fences writes the run never sees: a tool, a purpose's code, a tool child process that adopts its
parent's token. They can ask the registry without being handed the run. The registry records a fact of the process,
which is exactly its lifetime.

**Rejected.** The token on `AgentRun` passed everywhere (every tool and purpose signature would grow a parameter);
reading the token from the loaded conversation row (it may be another worker's token, so a fenced write would act in
its name).

**Would change if.** Every writer that needs the fence received the run as a parameter anyway.

## A release is one value object

**Rule.** Every way a worker turn hands back its claim is a static factory on `AgentRunRelease`, applied by the single
executor `applyRunRelease()` in a fixed order; probes run after the release, publishes only when the release wrote the
row.

**Reason.** The branches differ in a dozen fields (state, attempts, marker, events, status, probe, re-fire, promotion,
follow-ups). As a pure value object each branch is testable without a database and cannot forget a step; the executor
keeps the ordering bugs (a lost wake-up, a stale status) out of every branch at once.

**Rejected.** A hand-written release sequence per branch (the order drifted between branches and each new branch
re-introduced a lost wake-up); a status published before the release (a signal injected in between was lost).

**Would change if.** Releases stopped differing in more than the run state.

## A failed turn stays owed

**Rule.** A transient failure after the first heartbeat counts one attempt — also after real progress — and under the
cap releases IDLE with the handover marker stamped and the status running; over `MAX_RESUME_ATTEMPTS` the run ends
FAILED with a Retry. Deterministic failures (budget, provider error, an ineligible scope) end at once, without retries.

**Reason.** An owner's message must never be left unanswered silently, and a broken turn must never retry forever.
Counting after progress closes the loop "progress, then the same throw" that the recovery pass would otherwise re-drive
without end. A deterministic failure would only fail again.

**Rejected.** Ending every turn error FAILED at once (a transient blip lost the owner's turn); releasing IDLE without the
marker (no recovery pass selected the row, the message stayed unanswered); resetting the count after progress (an
endless re-drive); retrying by Messenger redelivery (it would race the recovery pass and re-run a half-executed turn).

**Would change if.** The loop could resume a turn at the exact step it failed in, making a re-drive idempotent by
construction.

## Recovery by markers and sweeps

**Rule.** Liveness lives in columns (`runHeartbeatAt`, `agentTurnOwedAt`, `resumeAttempts`, `reportOwedAt`) and is
repaired by stateless finders that a host runs on a schedule; each repair is a normal resume through the claim.

**Reason.** A worker can die anywhere — before its claim, between a stamp and an append, in a tool call. Data on the row
survives every such crash; a finder that reads it is idempotent, bounded by batch limits, and needs no in-process state.
Every won claim clears the marker, so a marker that outlives the quiet window is a turn owed and not claimed.

**Rejected.** In-process retry timers (lost with the process); a single reaper for every case (a stuck RUNNING row and a
lost IDLE resume need different selectors and windows); a re-fire without stamping first (a lost re-fire would be lost
again with no owner).

**Would change if.** The package shipped a durable job scheduler with per-conversation retries of its own.

## New rows are strict inserts under an index lock

**Rule.** A new message is a strict INSERT on `(aiConversationId, indexInConversation)` under a named lock per
conversation; a refused index is retried above the refused index, a bounded number of times, without a locking read.

**Reason.** The owner's rows arrive while a worker writes, and both allocate "newest + 1". An upsert merged two rows into
one (the owner's message overwritten); the strict insert makes the database the arbiter, and the lock only makes
collisions rare. A free lower index is kept so a step's call rows stay contiguous for the provider.

**Rejected.** INSERT … ON DUPLICATE KEY UPDATE for new rows (silent merges); trusting the named lock alone (not exclusive
on every database topology); a locking read of the winning row after a refusal (the winner is invisible to the snapshot, and a locking read of it
fails under snapshot isolation).

**Would change if.** Message indexes were allocated by the database itself (a sequence per conversation).

## Writes are fenced transactions with publications and undos

**Rule.** A write of the driven turn opens a claim-fenced write: index lock, transaction, the token re-checked under a
row lock; side effects run as publications after the outermost commit; in-memory changes register undos for a rollback.

**Reason.** A turn writes rows that belong together; a stolen claim must write nothing; a worker that reads a row
(embedding, realtime) must not be dispatched before the row is visible; memory must match the database after a
rollback.

**Rejected.** Callbacks passed into a transactional helper (closures in regular methods, nesting and error paths hard to
read — replaced by the write scope value object); dispatching side effects inline (the embedding worker dropped jobs for
rows it could not read yet).

**Would change if.** The writes of a turn could be made single-statement.

## Loop state is derived from rows

**Rule.** Steps consumed, the turn's cost, bounce counts and the plan's stamps are folds over the persisted rows,
re-derived at every step; only what one invocation needs between steps rides `AgentLoopCorrectionState`.

**Reason.** Crash-resume, chunk yields and claim steals reconstruct the same state for free; a stored counter would
drift whenever a write and its count land in different statements.

**Rejected.** Counters on the run or the conversation row.

**Would change if.** A fold became too expensive per step (it reads the loaded tail only, so the turn length bounds it).

## Chunked execution

**Rule.** A worker invocation runs at most `MAX_STEPS` steps, then yields with a continuation signal and the handover
marker, and the next invocation continues the same turn; sync paths run the remainder inline.

**Reason.** A long turn must not hold one worker for its whole length, must pick up a compaction that landed meanwhile,
and must be resumable after a worker restart between chunks.

**Rejected.** One invocation per turn regardless of length (a worker restart lost the whole turn; the context never
shrank mid-task); a separate worker pool for long turns (the chunking makes the pool independent of the length).

**Would change if.** A worker restart could hand over an in-flight invocation.

## Task plan items carry server-minted keys

**Rule.** Every item has a key the server mints from its parent's counter (`7.2.11`), never renumbered or reused within a
plan; order follows the stored order, not the key.

**Reason.** Everything that names an item — the platform's closing notes, the model's ticks, purpose declarations,
records — must keep naming the same item after inserts and drops. Positions moved gates and records onto the wrong items
and made old mentions point at new items.

**Rejected.** The model re-typing the whole tree and the server matching items by position or text (inserts and rewords
moved state, silent truncation at fixed sizes); model-chosen ids (collisions and reuse).

**Would change if.** Plans were never edited after creation.

## A keyed plan call is accepted or rejected whole

**Rule.** `update_task_plan` lists existing items by key (text fixed), new items by text, drops explicitly; any violation
rejects the entire call with every offending line and the plan's keys. `set_task_status` addresses by key only.

**Reason.** A partially applied structural change leaves a plan nobody intended; a full list of violations lets the model
fix the call in one step. A stored item is kept as an object, so its declarations and records cannot be lost by a
rewrite.

**Rejected.** Applying the valid part of a call; fuzzy addressing of ticks by text or number path.

**Would change if.** The model could not produce a valid keyed call often enough to be worth the strictness.

## The plan lives on the conversation

**Rule.** The active and finished plans live in the conversation's working-state JSON column, written as complete slices
token-fenced at loop level; the tool-call rows are the history.

**Reason.** The plan must survive compaction, tool-result clearing and worker hand-overs and be read on every step
without a join; a complete slice keeps the merge semantics of the column predictable.

**Rejected.** The plan in the context window only (lost to compaction); a separate plan table (another claim to fence,
another join per step).

**Would change if.** Plans became large enough that one JSON column per conversation turned into a write hotspot.

## One checked-stop rule

**Rule.** A final text is bounced at most once per chunk, only when the plan was touched in this turn (or a work signal
opened it), items are still owed after the candidate's own evidence, and the text hands nothing to the owner.

**Reason.** A model that announces instead of finishing must be caught, but a stop rule that fires on leftover plans or
on a question to the owner turns into an argument with the model. One bounce per chunk bounds the cost.

**Rejected.** Several escalating rules with exceptions per purpose (they contradicted each other and were hard to
predict); unlimited bounces.

**Would change if.** The models finished planned work without a reminder.

## Overhead costs by an atomic increment

**Rule.** Costs booked outside the message rows and tool calls are added to the conversation by one SQL increment per
recording (total, count, one JSON path per scope).

**Reason.** Turn workers and background workers book on the same row concurrently; a read-modify-write loses amounts and
would overwrite the claim columns.

**Rejected.** Writing the entity back; a separate cost table per booking (one more join for every cost view).

**Would change if.** A cost ledger outside the conversation became the single source for every view.
