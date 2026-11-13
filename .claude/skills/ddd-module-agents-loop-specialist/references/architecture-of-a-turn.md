# Architecture of a turn

**Reader:** host developer and package maintainer

Contents: 1. The conversation tree · 2. Entry points · 3. The drive · 4. The worker resume · 5. The step loop ·
6. Why the loop stopped · 7. Chunks, budgets and the yield · 8. After the loop · 9. Sync paths · 10. Changing the loop

## 1. The conversation tree

An `AIConversation` is one context window. A root conversation has a human (the owner) on the other side; a
delegation child is a conversation of its own whose "owner" is the orchestrating agent. A child carries
`delegatedFromMessageId` (the `delegate` call row that spawned it) and `rootConversationId` (null on a root;
`getTreeRootConversationId()` answers `rootConversationId ?? id`). The loop is the same for both: a child runs the
same `runConversationLoop()` on its own conversation, with delegation switched off (one level of delegation).
How children are spawned, admitted and reported: `ddd-module-agents-autonomy-specialist`.

A turn is not stored. `AgentTurn` and `AIConversationTurnMessages` are projections over the rows: a turn runs from one
initiator message (an owner row, a work signal, an orchestrator's task) to the next. The current turn is
`AIConversationMessages::buildCurrentTurn()`; its questions (`countModelCallSteps()`, `getCostInUSD()`, …) are asked
fresh at every step.

## 2. Entry points

| Entry | What it does |
|---|---|
| `AIConversationAgentService::driveConversation()` | the generic drive: find or create the conversation, append the owner's row (and an optional invisible start signal), then run the turn async or inline |
| `driveAdminConversation()` | `driveConversation()` with `AIConversationDriveScope::ADMIN` (impersonation, budget lift, model pick, truncate and branch, a sync turn on an IDLE conversation) |
| `startOrReopenConversationAtEntryPoint()` | a customer start that names a product entry point (`AIConversation::$entryPointKey`, checked against `ENTRY_POINT_KEY_PATTERN`); reopens the recent conversation of the same account, space and key instead of starting a new one |
| `startOrResumePurposedConversation()` / `getOrStartPurposedConversation()` | the purposed singleton per space and purpose; the agent speaks first off the purpose's kickoff signal (`ddd-module-agents-skills-and-purposes-specialist`) |
| `AIConversationTurnSignalsService::injectSignal()` | an inbound signal (a widget click, a fact of the host): a source a running turn picks up is written WAITING (merged into a waiting row of the same source while a claim is live), any other is appended index-safe; the conversation is then resumed, except when the source owes no work, or it is picked up by a running turn and a live claim exists |
| `resumeConversationIfReady()` | the single re-entry point of every worker, recovery pass and re-fire |

**The scope of a drive** (`AIConversationDriveScope`). CUSTOMER: the caller is the account, rights apply, the budget is
enforced, a model pick is honoured only when `getCustomerSelectableAgentModels()` lists it (otherwise ignored, never
rejected), always async. ADMIN: the existing conversation's account (else `$runAsAccount`, else the owner of the space)
is impersonated for the drive and restored in a `finally`.

**The entry-point reopen.** `startOrReopenConversationAtEntryPoint()` takes a named lock per account, space and key
(`AIConversationsService::lockEntryPointStart()`, wait `ENTRY_POINT_START_LOCK_TIMEOUT_SECONDS`), asks
`findReopenableConversationForEntryPoint()` and returns `AIConversationEntryPointStart::reopened()` (nothing appended,
no turn) or drives a new conversation stamped with the key (`started()`). A reopenable conversation is the account's
own root customer dialog with the same key, no purpose, no initiator, not closed and not FAILED, active within
`ENTRY_POINT_REOPEN_WINDOW_SECONDS`. A lock not free in time is logged and the start runs unserialised.

## 3. The drive

`driveConversation()` in order:

1. `AIConversationDriveRequest::buildFor()` sanitises the owner text and the start signal; a customer drive with
   neither is refused.
2. `AIConversationsService::findConversationToDrive()` decides the conversation, the space and the account. A new
   conversation is checked by `AgentSpacesService::requireAgentSpaceUsableByAccount()` (or
   `requireConversationStartWithoutAgentSpaceAllowed()` without a space). A customer continuing a closed conversation
   gets `ConversationClosedException`; a customer message on a FAILED root re-arms it
   (`AIConversationRunClaimsService::rearmRunForRetry()`).
3. A new conversation is validated before anything is stored (the wire configuration of its model,
   `resolveAgentWireConfigurationForNewConversation()`), then created (`findOrCreateConversationToDrive()`) and stamped
   once: initiator type, entry-point key, schedule, purpose, run settings, report channel, context retention.
4. The budget bypass is decided (`AIConversationDriveRequest::decideBudgetBypass()`) and persisted on the conversation,
   so the worker honours it; a plain customer drive resets it to false.
5. The start signal (if any) is written with `appendInboundSignal()`, then the owner's row with
   `persistInboundRowIndexSafe()`, then `recordInboundMessageActivity()` stamps `lastInboundMessageAt`.
6. Async (always for CUSTOMER, for ADMIN when not IDLE or a delegation child): `resumeConversationIfReady($conversation,
   async: true)` dispatches `AIConversationResumeMessage` FIRST, then the realtime status `running` is published.
   Sync (ADMIN on an IDLE root): an envelope `ENVELOPE_AI_CONVERSATION_TURN` is opened and `generateAgentReply()` runs
   inline, without a claim.

## 4. The worker resume

`AIConversationResumeHandler` (transport `ai_conversation_turn`, a CPU time limit of 1,800 s): load the conversation
fresh (a load failure is rethrown so Messenger retries), then `resumeConversationIfReady($conversation, async: false)`;
a drive failure is only logged — the release has already decided its outcome. Without an open envelope the handler
opens `ENVELOPE_AI_CONVERSATION_TURN` itself.

`resumeConversationIfReady()` (inline):

1. `AgentRun::forWorkerResume()`; `mintRunToken()` + `claimRun()`. Lost → return (a live worker drives).
2. `releaseRunOfIneligibleScope()`: a space or account that no longer permits automated work is released through
   `AgentRunRelease::afterScopeNotEligible()` before anything is loaded.
3. The chunk event is recorded (`ddd-module-agents-observability-specialist`).
4. Pre-drive, inside a catch that releases with `afterPreDriveFailure()` and rethrows: attach the space's relations
   (`loadAgentSpaceRelationsIfMissing()`), load the compaction cover (`findCompactionCoverNodes()`) and the bounded tail
   (`AIConversationMessagesService::findContextTailMessages()`), materialise supervisor steers, derive:
   `hasUnansweredInboundMessage()` (an owner row or an owed signal) and an unread settled delegation batch.
5. A supervisor tandem runs its checkpoint and releases (`afterSupervisorTandemTurn()`).
6. Nothing owed → reload, re-derive, `afterNoWork()` (re-fires when work landed meanwhile).
7. A turn that sub-agent notifications alone opened waits briefly for close run ends (the claim held).
8. A tool-run delegation child runs its one call without a model and finishes into its batch.
9. The conversation's `budgetBypass` becomes a grant (`AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS`) on the open
   envelope for this turn only; the support override of eligibility is activated when the run settings carry it.
10. `generateAgentReply(…, asyncDelegations: true, runToken: $runToken, compactAsync: true, agentRun: $agentRun)`.
11. Branch on what the turn produced and release (section 6 and [run-claims-release-and-recovery](run-claims-release-and-recovery.md)).

## 5. The step loop

`generateAgentReply()` preloads the skills that a composed widget of the inbound and the initiator type name, then
calls `runConversationLoop()`, which records the turn's overhead costs around `runConversationLoopSteps()`.

**Before the first step:** the unused speculative capabilities are cleaned up; the base system prompt is resolved once
per run (`AIConversationModelCallsService::resolveBaseSystemPrompt()`); the run's wire configuration is resolved and
validated once (`resolveAgentWireConfiguration()` — a rejected combination ends the run with its reason); the turn-start
decisions are sent and applied (`ddd-module-agents-decisions-and-guards-specialist`); the step budget is derived;
earlier one-instruction mandates are closed; variants are resolved; the turn-start signals are appended (plan
bookkeeping, owner-question picks, owner widget edits, the purpose widget, continue-after-pause).

**Each step** (`for` header re-derives `invocationStepLimit` from `AIConversationTurnBudget`):

1. Heartbeat (worker path) — false → `AgentRunClaimLostException`.
2. Cost cap: the current turn's cost ≥ `maxTurnCostUsd` → `AgentLoopStopReason::costCapReached()`.
3. Boundary work: the bounded wait for a platform sub-agent run the purpose asked for, the hand-over of signals that
   arrived during the turn (`appendSignalsThatArrivedDuringTheTurn()`), supervisor steers of a step beat, capability
   cleanup and unloading, the plan item's skill preload.
4. The offer: `AIConversationModelCallsService::buildOfferedToolSchemas()` → `AIConversation::buildOfferedTools()`,
   then the step's narrowing (a seeded plan drops `delegate` outside its closing act, a forced first step, the
   rejected-plan-tick brake `MAX_CONSECUTIVE_REJECTED_PLAN_TICKS` / `MAX_REJECTED_PLAN_TICKS_PER_TURN`, the budget
   closing step) into `AgentStepToolChoice` and `AgentStepToolRestriction`.
5. The model call: `performModelCallOfStep()` → `ModelResponse` (text, `ToolCalls`, usage, `RequestSnapshot`,
   `providerError`). A response written on a tool result the background screening removed is discarded and the step
   re-run once.
6. Provider error → `failFastOnProviderError()` (an ERROR row, then `AIModelCallFailedException`).
7. Stall classification and recovery; the checked stop; open tool obligations; the freshness retry
   ([stall-and-final-text](stall-and-final-text.md)).
8. `appendTurn()`: a final text becomes the assistant TEXT row; a tool step writes its prose head (only when not
   empty), then one PENDING call row per call, persisted before its execution, the database connection renewed
   (`AppMessageHandler::renewDatabaseConnection()`), `AgentToolCallDispatchService::dispatchToolCall()` (or the fork-join
   of parallel-safe calls), the follow-ups, and the call row again with its result
   (`ddd-module-agents-tools-specialist`). Exactly one row per step carries the step's usage stamp: the prose head, or
   the first call row.
9. Background screening of the step's untrusted results; eviction under tool granularity.
10. Exit or continue on the `AgentTurnOutcome`: final text, the text-less end of `wait_for_sub_agents`, awaiting
    delegates (the platform writes its own `wait_for_sub_agents` row), a repeat abort, or a forced tool-less next step.

## 6. Why the loop stopped

`runConversationLoopSteps()` sets one `AgentLoopStopReason` and returns it on `AgentReply::$loopStopReason`;
`generateAgentReply()` copies it to `AgentRun::$lastLoopStopReason`, which the release reads.

| Reason | Set when | Terminal report |
|---|---|---|
| `ANSWERED` | a final text with content, or a delivered owner report as the answer | no |
| `EMPTY_FINAL_TEXT` | a final step without text | yes (may yield) |
| `PARKED_FOR_DELEGATES` | a tool result started sub-agents whose ends resume the parent | no |
| `WAITING_FOR_SUB_AGENTS` | a completed `wait_for_sub_agents` call | no |
| `CLAIM_LOST` | heartbeat, a fenced write or a duplicate index under a claim (`claimLostAfter()` keeps an earlier answer) | no |
| `REPEATED_CALL_ABORTED` | the repeat guard | yes |
| `COST_CAP_REACHED` | the turn's cost cap | yes |
| `BUDGET_CLOSING_STALLED` | the budget closing step produced no owner message | yes (may yield) |
| `STEP_BUDGET_REACHED` | the loop ran out of steps without another reason | yes (may yield) |

A reason with a terminal report appends an ERROR row (`terminalErrorText()`) and delivers the run report
(`AIConversationOwnerReportDeliveryService::deliverRunReportNow()`, `ddd-module-agents-autonomy-specialist`), then marks
waiting sub-agent run-end notifications read.

## 7. Chunks, budgets and the yield

`AIConversationTurnBudget` is pure over the loaded conversation and rebuilt per question:

- Steps: an explicit `RunSettings::$maxStepsPerTurn` wins (clamped to `MAX_STEPS_PER_TURN_CEILING`); else a turn whose
  task plan was touched in this turn (or a work-signal turn of an autonomous purpose with an open plan) gets
  `PLANNED_TURN_BASE_STEPS` + `PLANNED_TURN_STEPS_PER_ITEM` × leaf count, clamped to [`MAX_STEPS`,
  `PLANNED_TURN_MAX_STEPS_CEILING`]; else `MAX_STEPS`. A tool-declared floor (`AgentWorkingState::$turnMinimumSteps`)
  raises it under the same ceiling.
- Cost: an explicit `maxTurnCostUsd` wins. Otherwise `PLANNED_TURN_DEFAULT_COST_CAP_USD` applies when
  `maxStepsPerTurn` is set, the plan was touched in this turn or a tool declared a step floor. Any other turn — an
  autonomous work-signal turn on an untouched plan included — has no cost cap.
- `deriveInvocationStepLimit()`: a worker invocation runs at most `MAX_STEPS` steps; a sync path runs the remainder.
- `isClosingStepDue()`: one step before the budget, once per owner turn, the last call runs without tools.

After the loop, `shouldYieldInvocationForContinuation()` decides the chunk yield: only a worker invocation, only for
`STEP_BUDGET_REACHED`, `BUDGET_CLOSING_STALLED` or `EMPTY_FINAL_TEXT`, only when the batch was used up and the turn's
budget is not. The yield appends a `SIGNAL_SOURCE_TURN_CONTINUATION` signal, stamps `agentTurnOwedAt`
(`recordInboundMessageActivity(…, stampAgentTurnOwed: true)`) and marks the reason `withYieldForContinuation()`. The
release then re-fires; the next invocation re-claims, reloads the cover and tail, and continues the same turn.

## 8. After the loop

In `generateAgentReply()`, best-effort and never for a delegation child: compaction when due
(`AIConversationCompactionService::compactConversationIfDue()`, logged at error level when it fails —
`ddd-module-agents-context-specialist`), then the display summary when stale
(`AIConversation::isDisplaySummaryStale()` → `AIConversationsService::generateAndPersistSummary()`, async through
`AIConversationSummarizeMessage` on the worker path). The summary writes the `summary` column alone.

After an answered root turn that re-fired nothing, `AIConversationTurnEndFollowUpsService::runFollowUpsOfAnsweredTurn()`
runs: the schedule's completed firing (not caught), memory curation, the supervisor checkpoint, the purpose-goal close
and the `AIConversationSettledOwnerTurnListener` services — each best-effort.

Before the first model call, the `AIConversationTurnStartListener` services may write rows the turn must read; the
loop reloads the tail when one answers true. Both listener interfaces are autoconfigured by tag
(`ddd-module-agents-specialist`).

## 9. Sync paths

The console, an evaluation harness and the ADMIN "wait for the reply" call `generateAgentReply()` directly: no claim,
`runToken` null, the whole remainder inline, compaction inline unless `compactAsync`. On a persisted conversation the
writes still take the index lock and the transaction; only the claim check is skipped. A conversation without an id
(an ephemeral evaluation run) is written in memory only, with transient ids from `AgentTransientMessageIds`.

## 10. Changing the loop

- A new step concern goes into its own service, called with the `AgentRun` as a parameter; the agent service keeps the
  drive, the worker entry, the loop and the release ([service-map](service-map.md)).
- A new way out of the loop is a new `AgentLoopStopReason` constant and factory; decide `mayYieldForContinuation()`
  and `endsWithATerminalReport()` for it.
- State a later step needs is derived from rows or carried on `AgentLoopCorrectionState` (one invocation) — never a
  property of a service.
- Every arrow across a worker boundary carries an id; the handler loads the entity fresh.
