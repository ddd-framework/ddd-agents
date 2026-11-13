# A turn that never answers

**Reader:** host developer and package maintainer

Contents: first look · the stored state to read · the symptom table · what the resume diagnosis says · the stuck-run list · for the package maintainer · full class names

The owner sent a message and nothing came back, or a run sits in one state forever. This reference maps what you
find in the stored conversation to the cause and to the service that repairs it. Why the states exist — the run
claim, the release, the failed-turn rule, the recovery passes — is explained by `ddd-module-agents-loop-specialist`;
the recovery commands themselves are templates of `ddd-module-agents-specialist`.

## First look

1. Run the host's inspect command (the template in the skill): the resume diagnosis, the integrity report, the
   delegation tree and the transcript of one conversation, all read-only.
2. Read the stored state columns of the conversation (below) and the newest rows of its tail.
3. Read the turn events of the newest owner message (`AIConversationTurnEventsService::findForConversation()` with
   its index): a `turnEnd` event with its outcome says how the last turn ended; no event at all means no worker ever
   started the turn ([trace-and-turn-events](trace-and-turn-events.md)).
4. Only then repair, through the pass named in the table.

## The stored state to read

| Column of `AIConversation` | Meaning |
|---|---|
| `runState` | one of the `AIConversationRunState` constants: `IDLE`, `RUNNING`, `QUEUED`, `FAILED`, `COMPLETED`, `CANCELLED`. `AWAITING_DELEGATES` only on rows written before: no release writes it any more (a parent waiting for its sub-agents rests `IDLE`), but the reaper, the expiry and the cancel still read it |
| `runHeartbeatAt` | the newest heartbeat of the worker that holds the run; each loop step touches it |
| `resumeAttempts` | failed attempts of the current turn; the attempt that takes it past `AgentRunRelease::MAX_RESUME_ATTEMPTS` (3) ends the run `FAILED` with 4; a deterministic stop writes 0 |
| `agentTurnOwedAt` | a turn was handed over to a continuation that has not run yet |
| `lastInboundMessageAt` | the newest owner message or inbound signal |
| `reportOwedAt`, `reportDeliveredAt` | a run owes the owner a report; delivered or not |
| `rootConversationId`, `delegationBatchId` | the conversation is a sub-agent; its parent waits for it |

## The symptom table

| What you find | Likely cause | Repaired by |
|---|---|---|
| `IDLE`, the newest row is the owner's message, `resumeAttempts` 0, no turn event for it | the resume message never reached a worker: the turn transport is not routed, no consumer runs `ai_conversation_turn`, or the dispatch was lost | the owed-work recovery, pass "inbound left idle": `AIConversationsService::findConversationIdsWithInboundLeftIdle()` → `AIConversationAgentService::resumeConversationIfReady()` with `async: true`; at once: `AIConversationsService::retryUnansweredTurn()` |
| `IDLE`, `resumeAttempts` 1 to 3, `agentTurnOwedAt` set, a `chunk` event but no `turnEnd` for the owner message | the turn threw (or its model call's transfer failed); the release kept it owed and stamped the handover marker | the owed-work pass "owed continuations": `findConversationIdsOwingContinuations()` after `AIConversationsService::AGENT_TURN_OWED_QUIET_SECONDS`; at once: `retryUnansweredTurn()` (the Retry route of `AgentConversationWritesTrait`). The pass "inbound left idle" catches a failed turn only when its release could not stamp the marker |
| `FAILED`, `resumeAttempts` 4, `turnEnd` outcome `failed` | the retry cap is exhausted; never re-run by a machine | `retryUnansweredTurn()` (owner or admin Retry); read the error rows and the request snapshot of the failed step first |
| `FAILED`, `resumeAttempts` 0, `turnEnd` outcome `failed` | a deterministic stop: the AI budget is used up, the provider answered an error, or the space or account is suspended (a scope that can come back) — `AgentRunRelease::afterBudgetExhausted()`, `afterProviderError()`, `afterScopeNotEligible()` | no automatic retry; `retryUnansweredTurn()` once the cause is gone, or the owner's next message re-arms it; budgets: `ddd-module-ai-specialist` |
| `CANCELLED`, `turnEnd` outcome `cancelled` | the space or the account is gone for good (erased, deleted) | never resumed; `retryUnansweredTurn()` refuses it too |
| `FAILED` after a `RUNNING` (or, on an older row, `AWAITING_DELEGATES`) whose heartbeat was silent past `AIConversationsService::RUN_REVIVE_MAX_AGE_SECONDS` | the reaper expired it (`expireStuckConversationsPastMaxAge()`), cleared the run token and armed `reportOwedAt`; `resumeAttempts` unchanged | `retryUnansweredTurn()` |
| `IDLE`, `resumeAttempts` 0, `agentTurnOwedAt` older than the quiet window | a turn handed over its continuation (step budget, chunk end) and the next chunk never ran, or a sub-agent was admitted from the queue and its dispatch was lost | the owed-work pass "owed continuations": `findConversationIdsOwingContinuations()` → `resumeConversationIfReady()` |
| `RUNNING`, `runHeartbeatAt` older than `AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS` | the worker died: a crash, a memory limit, a deploy that killed the consumer before the turn ended (stop grace shorter than a turn) | the reaper: `AIConversationsService::findStuckConversationIds()` → a fresh `AIConversationResumeMessage`; the new claim takes over the stale row |
| `RUNNING`, heartbeat fresh, for minutes | the turn is working: a slow tool, a long model call, many steps | nothing to repair; read the request snapshots and tool timings ([debugging-a-turn](debugging-a-turn.md)); the turn handler sets PHP's time limit to 1800 s, which counts the script's own execution time — a wait on the network can outlast it; a wall-clock limit is the host's worker configuration |
| parent `IDLE` (realtime status running), its sub-agent `RUNNING` past its deadline with a silent heartbeat | the sub-agent's worker died (a child with a fresh heartbeat only gets its deadline extended) | the reaper: `AIConversationDelegationBatchesService::findTimedOutUnfinishedBatchIds()` → `timeoutBatch()`, which ends the run timed out (`timeOutSubAgentRun()`) and returns null; the run-end notification wakes the parent |
| parent `IDLE`, its sub-agents ended, no run-end notification on the parent | a lost run-end notification or resume | the reaper: `findEndedUnreportedSubAgentRunDeadlineIds()` → `AIConversationDelegationNotificationsService::notifyParentOfSubAgentRunEndOfDeadline()` |
| (rows written before one sub-agent per call) parent `AWAITING_DELEGATES` or `IDLE`, its group finished, results unread | a lost resume of the parent after an older fork-join group finished | the reaper: `findParentConversationIdsWithUnreadChildResults()`, then a resume of the parent; for such a group `timeoutBatch()` returns the parent id to resume |
| a sub-agent `QUEUED` for long, later its run ended timed out | it waits for a slot under `AIConversationRunClaimsService::DELEGATION_CONCURRENCY_CAP`, and the promotion after a sibling settled was lost. The reaper does not expire a `QUEUED` row; at each deadline it extends the run's deadline while the child waits, for up to `AIConversationDelegationBatchesService::SUB_AGENT_QUEUED_MAX_WAIT_SECONDS` after the deadline row was written, then `timeOutSubAgentRun()` ends the run timed out and the parent is told | `admitQueuedDelegationChildren($rootConversationId)`, then dispatch a resume for each id it returns (the admission stamps `agentTurnOwedAt`, so the owed-continuations pass re-dispatches a lost one). Only an older fork-join batch force-fails its queued members |
| the resume diagnosis reports `tailsDisagree()` | the compaction cover reaches at or past the unanswered owner message, so the bounded tail looks answered and the resume releases `IDLE` | **nothing in the package answers it**: the resume, every recovery pass and `retryUnansweredTurn()` read the same bounded tail, see it answered and do nothing. By hand: a new owner message (the owner re-sends, or an admin drive appends one) lies after the cover edge, so the next resume drives. The cause is a defect of context reduction: `ddd-module-agents-context-specialist` |
| a `turnEnd` event with outcome `gaveUp` or a pause report as the last row | the turn stopped on its step or cost budget, or the stall handling gave up | not stuck: the owner got the pause text; budgets and stall handling: `ddd-module-agents-loop-specialist` |
| the answer row exists, the owner's screen shows nothing | no realtime push reaches the frontend (`AIConversationRealtimeSignalService` does nothing by default) | the host's realtime channel: `ddd-module-agents-widgets-specialist`; the run state route of `AgentConversationReadsTrait` shows the truth |
| `reportOwedAt` set, `reportDeliveredAt` empty, run ended | the inline report delivery was lost; a crashed run is selected only after the reaper expired it | the owed-work pass "owed reports": `findConversationIdsOwingReports()` → `AIConversationAgentService::deliverOwedReportLate()`; owner reports: `ddd-module-agents-autonomy-specialist` |
| any stuck state, but no pass ever picks it up | the row belongs to another environment, or the host never scheduled the recovery and reaper commands | check `AIConversationsService::currentEnvironment()` against the row; schedule the commands (`ddd-module-agents-specialist`) |
| `IDLE` with an unanswered owner message, never resumed although the passes run | the owed passes never resume an evaluation run (`runSettings.evalRun`); the pass "inbound left idle" looks back only `AIConversationsService::LOST_RESUME_LOOKBACK_SECONDS` (one day) and skips sub-agents (their parent's fan-in owns them) | `retryUnansweredTurn()` for a root conversation; an eval run is the runner's to drive |
| `AiBudgetExceededException` in the log | the account's AI budget refused a model call | not a loop defect; budgets, the budget lift and envelopes: `ddd-module-ai-specialist` |

## What the resume diagnosis says

`AIConversationResumeDiagnosis::buildFor($conversation, $batchesService)` (pure reads, no turn):

| Field or method | Read it as |
|---|---|
| `$stateRows` | the stored state as printed lines: run state, heartbeat, attempts, delegation, model override, budget bypass, message count |
| `$coveredUpToIndex`, `$coverNodes` | how far the compaction summary reaches; the resume reads only the raw rows after it |
| `$hasUnansweredInboundOnDeriveTail` / `$hasUnansweredInboundOnFullTail` | whether the bounded tail and the full history see an unanswered owner message |
| `tailsDisagree()` | true: the cover hides the owed message (the silent-drop signature) |
| `$settledUnfoldedBatchId`, `$hasUnsettledBatch` | delegation work the parent still has to read, or still waits for |
| `wouldDrive()` | true: a resume now would run a turn; false: it would release `IDLE` (the diagnosis's own verdict line still names `AWAITING_DELEGATES` while a group is unsettled; the release writes `IDLE`) |
| `toLlmMarkdown()` | the whole diagnosis as text, with a verdict line |

A `wouldDrive()` of true on an `IDLE` conversation means only the trigger is missing: a recovery pass or a Retry
answers it. A false on a conversation the owner is waiting on means the stored rows do not show an owed message:
check `tailsDisagree()` and the truncation of the rows (`truncatedAt`).

## The stuck-run list

`AIConversationStuckRunsReadService::findStuckRuns()` returns every conversation the reaper and the owed-work
recovery would act on right now, as `AIConversationStuckRuns` — pure SELECTs through the same finders, nothing acts.
Each `AIConversationStuckRun` carries its `$reasons` (the `REASON_*` constants; `REASON_DESCRIPTIONS` names the pass
that acts on each), the run state, the heartbeat age, the attempts and the owed stamps. A host prints it from a
console command or an admin route; a growing list means a recovery command is not running.

## For the package maintainer

- `AIConversationResumeDiagnosis::loadDeriveTail()` copies the load of
  `AIConversationMessagesService::findContextTailMessages()` by hand. A change of the context tail changes the
  diagnosis in the same commit, or the diagnosis lies.
- An owed owner message hidden by the compaction cover (`tailsDisagree()`) has no repair in the package: every
  reader that decides whether work is owed reads the bounded tail. A fix belongs in the compaction floor or in a
  recovery path that checks the full tail.
- A new terminal or parked run state needs a row in the table above, a place in the `AIConversationRunState`
  groups the reaper reads (`NON_TERMINAL`) and, when a pass repairs it, a `REASON_*` on `AIConversationStuckRun`.
- A new release branch that leaves a turn owed must leave a state one of the passes selects; otherwise this table
  gets a row with "never repaired".

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Entities\Conversations\AIConversation` ·
`DDD\Domain\AI\Services\Conversations\AIConversationAgentService` ·
`DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService` ·
`DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService` ·
`DDD\Domain\AI\Services\Conversations\AIConversationMessagesService` ·
`DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationResumeDiagnosis` ·
`DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage` ·
`DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService` ·
`DDD\Domain\AI\Entities\Conversations\AIConversationRunState` ·
`DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRun` ·
`DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRuns` ·
`DDD\Domain\AI\Services\Conversations\Recovery\AIConversationStuckRunsReadService` ·
`DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService` ·
`DDD\Domain\AI\Services\Conversations\AIConversationsService` ·
`DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationReadsTrait` ·
`DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationWritesTrait` ·
`DDD\Domain\AI\Entities\Conversations\Agent\AgentRunRelease` · `DDD\Domain\AI\Exceptions\AiBudgetExceededException`
