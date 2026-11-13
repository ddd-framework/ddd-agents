# Run claims and release, failed turns, recovery

**Reader:** host developer and package maintainer

Contents: 1. Run states · 2. The claim · 3. Heartbeats and steals · 4. AgentRun and the token registry · 5. The
release · 6. Failed turns stay owed · 7. Retry · 8. Recovery: what the host's passes call · 9. Claims while sub-agents
work · 10. Adding a release branch

For the symptom table "a turn that never answers" see `ddd-module-agents-observability-specialist`; the recovery
commands a host must write, with their templates, are in `ddd-module-agents-specialist` (host duties). This file is the
mechanism they call.

## 1. Run states

Columns on the conversation row: `runState`, `runToken`, `runHeartbeatAt`, `resumeAttempts`, `agentTurnOwedAt`.

| State | Meaning |
|---|---|
| `IDLE` | at rest, claimable |
| `RUNNING` | a worker holds the claim and heartbeats |
| `QUEUED` | a delegation child waiting for a slot of its tree |
| `AWAITING_DELEGATES` | still read (older rows, cancel); no release writes it any more |
| `COMPLETED` / `CANCELLED` | closed: no claim, no customer message |
| `FAILED` | the retry cap is used up; terminal for workers, not for the owner (section 7) |

`AIConversationRunState::of()` and `AIConversation::getRunState()` answer named questions (`isClosed()`,
`isTerminal()`, `holdsALiveTurn()`, `isAtRestOrFailed()`, `isRunningOrQueued()`, …). Use them; never compare against a
literal list.

**IDLE does not mean the agent is done.** `AIConversationRunClaimsService::isTurnStillUnderwayWhileIdle()` answers, for
an IDLE row, whether its latest turn is still underway — three indexed reads: the owed-turn marker `agentTurnOwedAt` is
set (a yield hand-over or a failed turn under the cap); a sub-agent whose end resumes the conversation is unfinished;
or a sub-agent run-end notification still waits for the conversation's turn. A reader that reports progress (a task
projection, a revival of a lost kickoff, the cancel guard) asks it instead of reading IDLE as finished.

## 2. The claim

`AIConversationRunClaimsService::claimRun($conversation, $runToken)` is ONE conditional UPDATE:
`runState = RUNNING`, the new token, `runHeartbeatAt = now`, `agentTurnOwedAt = NULL`, and the environment slice of the
working state — where the row is not RUNNING, or its heartbeat is missing or older than `RUN_HUNG_AFTER_SECONDS`, and
it is neither terminal nor QUEUED. One affected row = the single winner; the token goes into the process registry.
The claim is committed at once; nothing holds a transaction across a model call.

The claim is the exactly-once gate against duplicate resumes: sibling run ends, a broker redelivery, the recovery
passes and an owner who writes while a turn runs. Whatever resumes, only one process drives.

`agentTurnOwedAt` (the handover marker) is cleared by every won claim. Besides the claim, only the owed-turn pass
(`clearAgentTurnOwed()`), a cancel (`cancelLiveRun()`), a child re-arm (`rearmTerminalDelegationChild()`) and an admin
truncate clear it. It is stamped by a chunk yield, by the failure releases under the cap, by `rearmRunForRetry()`, and
as the slot reservation of an admitted delegation child. A marker that survives therefore means "owed, not yet
claimed" — a lost hand-over when it outlives the quiet window.

## 3. Heartbeats and steals

- `heartbeat($conversation, $runToken)` at the top of every step: updates `runHeartbeatAt` where token and RUNNING
  match, then answers by a row-match read (`holdsRunClaim()`). False → `AgentRunClaimLostException` → the loop's one
  silent exit (`AgentLoopStopReason::claimLost()`): it appends nothing more and forgets its token.
- `touchRunningHeartbeat($conversationId)` keeps a RUNNING row alive from inside a long tool call; it never revives a
  released row.
- A RUNNING row whose heartbeat is older than `RUN_HUNG_AFTER_SECONDS` is claimable again: a stuck run is revived by
  dispatching a resume, whose claim steals the row. The old worker then loses at its next heartbeat or fenced write.
- `hasLiveRunClaim()` (RUNNING with a fresh heartbeat) is the question an injector asks: "will a running turn read my
  signal, or must I resume?"

## 4. AgentRun and the token registry

`AgentRun` is one invocation of the loop: options (`AIConversationTurnOptions`), timings, the worker job receipt,
per-run memos, the step counters (`stepsConsumedBeforeInvocation`, `invocationStep`, `invocationStepLimit`), the
resolved wire configuration and `lastLoopStopReason`. It is built at the entry, passed as a parameter, never persisted
and never stored on a service (a `sync://` job can run inside another job on the same instance).

| Factory | Built by |
|---|---|
| `AgentRun::forWorkerResume()` | `resumeConversationIfReady()` |
| `AgentRun::forTurnEntry()` | `generateAgentReply()` without a run (console, evaluation, sync drive) |
| `AgentRun::forInlineChild()` | an inline delegation child (`runInlineChildTurn()`) |
| `AgentRun::outsideARun()` | rows written between runs (a recovery pass, a late report) |

The run token is NOT on `AgentRun`. The static registry of `AIConversationRunClaimsService` is the only authority:
`getRunTokenClaimedInThisProcess()`, `forgetRunTokenClaimedInThisProcess()`, `adoptRunTokenOfParentProcess()` (a tool
child process writes under its parent's claim). A token another worker left on a loaded row is never used to fence a
write. `AgentWorkerStateResetter` clears the registry before every job unless a fenced write is open.

## 5. The release

`AgentRunRelease` is the whole decision of one release, a pure value object (the clock and the derives are inputs):
run state, attempt count, handover marker, turn-end event outcome, the schedule's run-terminal record, the realtime
status, where the lost-wakeup probe runs (`AgentRunRelease::WAITING_SIGNAL_PROBE_*`: none, before the publish, after the publish), the
re-fire, the promotion of queued children, the settled-turn follow-ups, the stall give-up reply, and whether a
delegation child's terminal owns the release (`finishesIntoDelegationBatch`).

| Factory | When | Result |
|---|---|---|
| `afterAnswered()` | a root turn answered | IDLE, attempts 0, turn end ANSWERED; owner input that arrived mid-turn re-fires, else the probe runs before the publish; the follow-ups run only when nothing re-fired |
| `afterTurnWithoutRealProgress()` | inbound owed, only loop steering appended | +1; under the cap IDLE, re-fire; over it the give-up reply (`STALL_GIVE_UP_REPLY` path), IDLE 0 |
| `afterNoWork()` | nothing owed at the first derive | IDLE; re-fire if work arrived after the reload, else the turn-end event late + probe |
| `afterDelegationsStarted()` | async sub-agent work that resumes the parent is unfinished | IDLE 0, status running, probe, promote |
| `afterWaitingForSubAgents()` | the turn ended with `wait_for_sub_agents` | IDLE 0, status running; owner input re-fires; probe; promote |
| `afterChildYield()` | a child's tail still owes a turn | IDLE 0, re-fire |
| `afterDelegationChildVerdict()` | a child produced its verdict | the child's terminal owns the release |
| `afterSupervisorTandemTurn()` | the tandem ran its checkpoint | IDLE 0 |
| `afterPreDriveFailure()` | a throw before the drive | +1; under the cap IDLE + marker, status running; over it FAILED |
| `afterTurnError()` | any other error after the first heartbeat | +1; under the cap IDLE + marker, status running; over it FAILED |
| `afterModelCallTransportFailure()` | `AIModelCallTransportFailedException` | as `afterTurnError()`, except that over the cap a delegation child in a batch publishes no status of its own when it yields |
| `afterIndexLockUnavailable()` | `MessageIndexLockUnavailableException` | +1; under the cap IDLE + marker, status idle; over it FAILED |
| `afterBudgetExhausted()` / `afterProviderError()` | `AiBudgetExceededException` / `AIModelCallFailedException` | deterministic: FAILED, attempts 0, no retry |
| `afterScopeNotEligible()` | `AIScopeNotEligibleException` or an ineligible scope at the claim | gone for good: CANCELLED, no failed widget; otherwise FAILED, attempts 0 |
| `afterDelegationChildTerminalThrew()` | the child's terminal threw | FAILED with the counted attempts |

Over the cap in a delegation child that belongs to a batch, every failure factory finishes into the batch instead
(`releaseAfterTurnFailure()` writes the child's FAILED terminal first).

**The executor** `AIConversationAgentService::applyRunRelease()` applies a release in this order: give-up reply →
turn-end event → `releaseRun()` (token-guarded; it writes state, attempts and marker in one UPDATE) → schedule record →
probe before publish → realtime publish → probe after publish → async re-fire → queue promotion. Two rules ride on the
order:

- Every probe runs AFTER the release. A signal injected while the claim was held dispatched no resume (the injector saw
  a live claim); probed before the release it would be lost.
- The realtime status is published only when `releaseRun()` wrote the row. A release that lost its token (stolen, or a
  second release after a throw in a follow-up) publishes nothing — the row's owner already did.

## 6. Failed turns stay owed

The rule: a turn error after the first heartbeat counts one attempt — also after real progress — and under
`AgentRunRelease::MAX_RESUME_ATTEMPTS` the run goes IDLE with `agentTurnOwedAt` stamped (the PHP clock) and the realtime
status `running`. The owed-turn pass of the host's recovery command re-drives it after its quiet window. Over the cap
the run ends FAILED with turn-end outcome FAILED and status `failed`; the owner sees the failed widget and its Retry.

Counting after progress matters: the recovery pass re-drives every owed turn, so a turn that appends a row and then
throws the same way would loop forever if progress reset the count.

How the exceptions reach the release (`resumeConversationIfReady()`):

| Exception | Rethrown? | Why |
|---|---|---|
| `AiBudgetExceededException` | no | deterministic; the loop already wrote the ERROR row (`ERROR_CODE` as signal source) and the template report |
| `AIScopeNotEligibleException` | no | deterministic; the loop wrote the ERROR row |
| `AIModelCallFailedException` | no | a provider error is deterministic; a redelivery would repeat it |
| `MessageIndexLockUnavailableException` | no | transient; a redelivery would race the recovery pass |
| `AIModelCallTransportFailedException` | no (logged at error level) | transient; a Guzzle transfer failure, wrapped in `sendModelCallThroughEgress()` |
| any other `Throwable` | yes | the handler logs it; the release already left the turn owed |

A transport failure is the HTTP transfer itself failing: a connect timeout, the read timeout
(`ArgusAILanguageModelTrait::DEFAULT_ARGUS_TIMEOUT`), a dropped connection, or a Guzzle response exception raised during
the transfer; the Guzzle exception rides as the previous exception. An HTTP error status of the provider is not one:
the Argus layer sends with `http_errors` false, so a 4xx or 5xx arrives as a response with an error body and is read as
a provider error (`AIModelCallFailedException`, no retry).

## 7. Retry

`AIConversationsService::retryUnansweredTurn()` (the owner's Retry): only for an IDLE or FAILED row with an unanswered
inbound in its tail; `AIConversationRunClaimsService::rearmRunForRetry()` is one guarded UPDATE on a root (a FAILED or IDLE root only) that sets IDLE, clears the token and stamps the marker; it resets
`resumeAttempts` only for a FAILED row, so a broken turn still reaches FAILED however often the owner clicks. Then one
recovery resume is dispatched under the conversation's account. If the update lost because a worker re-claimed the row
meanwhile, the retry counts as underway. A new customer message on a FAILED root re-arms it the same way
(`findConversationToDrive()`).

A finished delegation child is re-armed by `rearmTerminalDelegationChild()` (QUEUED, admitted like every child) — never
by `rearmRunForRetry()`.

## 8. Recovery: what the host's passes call

The package ships the finders and the re-drive, and no code that runs them: the passes are console commands of the
host (the duty and its templates: `ddd-module-agents-specialist`). What follows is a recipe for the host, built only from
package calls. Each pass impersonates the conversation's account around a dispatch and skips a conversation without
one. Every finder is scoped to the current environment. The owed-report, owed-turn and lost-resume finders also skip
evaluation runs; the two reaper finders (`findStuckConversationIds()`, `expireStuckConversationsPastMaxAge()`) do not,
so they revive and expire evaluation runs too.

**Stuck runs (the reaper).**
- `AIConversationsService::findStuckConversationIds()`: RUNNING with a heartbeat older than `RUN_HUNG_AFTER_SECONDS`, or
  AWAITING_DELEGATES quiet past `FOLD_OWED_STALE_AFTER_SECONDS`, but not older than `RUN_REVIVE_MAX_AGE_SECONDS` → dispatch
  a resume (its claim steals the row).
- `expireStuckConversationsPastMaxAge()`: older than the revive window → FAILED: one SELECT of the candidates, then
  one guarded UPDATE that re-checks state and staleness per row (the returned ids are the candidates), with
  `reportOwedAt` stamped so the owed-report pass delivers the report late. Late delivery for a crashed worker therefore
  depends on this sweep.
- The delegation sweeps (deadlines, lost notifications) and the purposed settle: `ddd-module-agents-autonomy-specialist`.

**Owed reports.** `findConversationIdsOwingReports()` → `deliverOwedReportLate()` (claims the delivery atomically);
on failure `AIConversationOwnerReportDeliveryService::deferOwedReport()`.

**Owed turns.** `findConversationIdsOwingContinuations()`: IDLE with `agentTurnOwedAt` older than
`AGENT_TURN_OWED_QUIET_SECONDS`, oldest first, at most `OWED_CONVERSATIONS_BATCH_LIMIT`. Load the newest rows
(`AIConversationMessagesService::findNewestMessages()`); if `lastWireMessageIsFinalAssistantText()` the turn was
answered → `clearAgentTurnOwed()`; else `resumeConversationIfReady($conversation, async: true)`. A re-drive that throws →
`deferOwedTurn()` (re-stamps the marker so a broken row cannot take a batch slot every tick).

**Lost resumes.** `findConversationIdsWithInboundLeftIdle()`, IDLE without a marker, no delegation child, inbound within
`LOST_RESUME_LOOKBACK_SECONDS`, newest inbound first, in two shapes: an inbound newer than the last heartbeat and quiet
past the window (a worker consumed the resume and died before claiming); or `resumeAttempts` 1..cap with a quiet
heartbeat and a newest TEXT or SIGNAL row that is not the agent's (a failed turn whose marker could not be written).
Skip a row whose newest wire message is the answer, a counted row without an unanswered inbound, and a row that is no
longer IDLE or has a live claim; then `deferOwedTurn()` FIRST and resume — if this re-fire is lost too, the owed-turn
pass owns it from then on.

**Read only.** `AIConversationStuckRunsReadService::findStuckRuns()` returns the same candidate sets as
`AIConversationStuckRuns` with the row facts, without acting — for a diagnostics view.

**Recover on read.** `recoverIfStuck()` (AWAITING_DELEGATES only) and `recoverLostKickoffIfStale()` (an IDLE kickoff that
never started) dispatch one recovery resume and bump the heartbeat first (`markRecoveryDispatched()`), so a burst of
reads dispatches once per window.

## 9. Claims while sub-agents work

A parent never holds its claim while its async children run: the turn that started them releases IDLE
(`afterDelegationsStarted()`, or `afterWaitingForSubAgents()` after a text-less end) with status `running`. Each child
claims its own row; the cap per tree is `DELEGATION_CONCURRENCY_CAP` (the rest wait QUEUED and are promoted when a slot
frees — level-triggered by every release that asks `promotesQueuedDelegations`). A run end notifies the parent with a
WAITING signal: a parent turn that is running reads it at its next model call; an idle parent is resumed; a run end that
landed after the parent's last model call is caught by the release probe. The parent stays conversational meanwhile:
an owner message drives a normal turn. How children claim, end and report: `ddd-module-agents-autonomy-specialist`.

`AIConversationRunClaimsService::cancelLiveRun()` cancels a run and its unfinished sub-agent runs in one read-committed
transaction that locks the conversation row first:

- QUEUED, RUNNING and AWAITING_DELEGATES are cancelled; an IDLE row only while `isTurnStillUnderwayWhileIdle()` holds or
  the newest owner text has no assistant text after it — re-checked under the lock, so a completion that landed after
  the caller's read is never overwritten; a terminal or answered row is left alone (no write, no publish).
- The write is `runState` CANCELLED, `runToken` and `agentTurnOwedAt` NULL. Each unfinished sub-agent run ends CANCELLED
  with `DelegationSubAgentRun::FAILURE_REASON_CANCELLED_BY_OWNER`, stamped reported, and its deadline row is ended, so
  the reaper never times it out into the parent; the child rows go CANCELLED in the same transaction.
- After the commit ONE change signal with `AIConversationRealtimeSignalService::STATUS_CANCELLED` goes out.
- CANCELLED is closed: no claim takes the row, a new owner message is refused, and a run-end notification that still
  reaches the parent neither ticks it running nor resumes it. The worker that still runs the turn loses at its next
  heartbeat and its release becomes a no-op.

## 10. Adding a release branch

1. Add a static factory on `AgentRunRelease` that sets only the fields its branch needs; reuse `countingAttempts()` /
   `countingOneMoreAttempt()`, `endingFailed()`, `owingTheTurnAgain()`, `finishingIntoDelegationBatch()`.
2. Call `applyRunRelease()` with it from the branch in `resumeConversationIfReady()` — never a hand-written sequence.
3. If the branch can leave inbound unanswered, it stamps the marker or re-fires; a release that leaves an owner's
   message IDLE without either is the lost-turn shape.
4. Pin the factory's fields in a unit test next to `AgentRunReleaseScopeNotEligibleTest`.
