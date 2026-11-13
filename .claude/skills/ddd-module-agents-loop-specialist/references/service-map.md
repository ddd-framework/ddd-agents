# Where does what live — the service map

**Reader:** package maintainer

Contents: 1. The rule · 2. Services of this skill · 3. Value objects of the loop · 4. Collaborators owned by other
skills · 5. Drive → loop → step → release · 6. Where a new method goes

Folders are relative to `src/Domain/AI/` of the package. The owning skill of every file is the ownership manifest of
`ddd-module-agents-specialist`.

## 1. The rule

`AIConversationAgentService` (`Services/Conversations/`) keeps four jobs: the drive (`driveConversation()`,
`driveAdminConversation()`, `startOrReopenConversationAtEntryPoint()`, the purposed starts), the worker entry
(`resumeConversationIfReady()`), the loop and its step (`generateAgentReply()`, `runConversationLoop()`, `appendTurn()`)
and the release (`applyRunRelease()`, `finishDelegationChild()`). Everything else is a service per concern. A service
resolves its collaborators through `DDDService::instance()->getService()` at the call site, receives the `AgentRun` as a
parameter and never stores it. Rows of a run go through `AIConversationMessagesService::persistMessageUnderRun()`.

The agent service implements `AgentModelCallRunnerInterface` (a collaborator such as the supervisor makes a model call
through `performModelCall()` without importing the loop) and `AgentChildTurnRunnerInterface` (an inline child turn).

## 2. Services of this skill

| Service | Responsibility | Key methods |
|---|---|---|
| `AIConversationAgentService` | drive, worker entry, loop, release | `driveConversation()`, `resumeConversationIfReady()`, `generateAgentReply()`, `runConversationLoop()`, `appendTurn()`, `applyRunRelease()`, `deliverOwedReportLate()`, `settlePurposeConversationIfGoalReached()` |
| `AIConversationRunClaimsService` | the claim, its token registry, heartbeat, release, guarded run-state transitions, retry re-arm, child admission, sub-agent run reads, cancel | `mintRunToken()`, `claimRun()`, `heartbeat()`, `touchRunningHeartbeat()`, `releaseRun()`, `transitionRunState()`, `closeConversation()`, `rearmRunForRetry()`, `rearmTerminalDelegationChild()`, `admitQueuedDelegationChildren()`, `hasLiveRunClaim()`, `isTurnStillUnderwayWhileIdle()`, `cancelLiveRun()` |
| `AIConversationMessagesService` | message loads, the index lock, claim-fenced writes, inbound and waiting-signal writers, capability-column writes, sub-agent record writes, truncate, widget rows of tools | `findContextTailMessages()`, `findNewestMessages()`, `lockMessageIndex()`, `openClaimFencedWrite()`, `commitClaimFencedWrite()`, `rollBackClaimFencedWrite()`, `persistMessageUnderRun()`, `persistNewRowUnderRunClaim()`, `persistInboundRowIndexSafe()`, `appendInboundSignal()`, `persistSignalWaitingForRunningTurn()`, `findAndMarkReadSignalsWaitingForRunningTurn()`, `truncateFrom()`, `appendWidget()` |
| `AIConversationsService` | conversation rows: finders, single-column persists, working-state slices, the drive target, entry-point reopen and lock, recovery finders, retry, summary, overhead costs, cost summaries | `findConversationToDrive()`, `findOrCreateConversationToDrive()`, `recordInboundMessageActivity()`, `persistTaskPlanSlice()`, `persistAgentWorkingStateSlice()`, `findReopenableConversationForEntryPoint()`, `lockEntryPointStart()`, `findConversationIdsOwingContinuations()`, `findConversationIdsWithInboundLeftIdle()`, `findStuckConversationIds()`, `retryUnansweredTurn()`, `generateAndPersistSummary()`, `addOverheadCostsToConversation()`, `sumConversationTreeCostsByRootConversationId()` |
| `AIConversationTransactionsService` | read-committed transactions for the run-end and lock transactions | `beginReadCommittedTransaction()`, `beginReadCommittedTransactionOrNestInOpenTransaction()` |
| `AIConversationModelCallsService` | the model call of a step, the offer's wire schemas, the base prompt, the wire configuration, the customer model whitelist | `performModelCallOfStep()`, `performModelCall()`, `buildOfferedToolSchemas()`, `resolveBaseSystemPrompt()`, `resolveAgentWireConfiguration()`, `reconstructSentPayloadForDisplay()`, `getCustomerSelectableAgentModels()` |
| `AIConversationTurnSignalsService` | signals into a running turn, the inbound signal entry, the turn-start signals, bounces and retries before a final text | `injectSignal()`, `appendSignalsThatArrivedDuringTheTurn()`, `replaySignalsThatArrivedAfterTheLastModelCall()`, `appendMidTurnOwnerInboundNote()`, `bounceCandidateTextOnOpenToolObligations()`, `retryCandidateTextOnFreshOwnerInbound()`, `armBudgetClosingStep()`, `attachOwnerQuestionWidget()` |
| `AIConversationMessagePublicationService` | the publications of a written row (embedding, console line, realtime tick) | `publishWrittenMessage()` |
| `AIConversationTurnEndFollowUpsService` | the follow-ups of an answered turn, in order | `runFollowUpsOfAnsweredTurn()` |
| `AIConversationStuckRunsReadService` | the recovery candidate sets, read only | `findStuckRuns()` |

The message handlers of this skill: `AIConversationResumeHandler` / `AIConversationResumeMessage` (one turn) and
`AIConversationSummarizeHandler` / `AIConversationSummarizeMessage` (the display summary). The egress classes:
`ArgusAIAgentConversation` (the agent's model call), `ArgusAIConversation`, `ArgusAIConversationSummary`. The
repositories: `DBAIConversation`, `DBAIConversations`, `DBAIConversationMessage`, `DBAIConversationMessages`.

## 3. Value objects of the loop

Pure decisions and per-run state live on value objects in `Entities/Conversations/Agent/` and `…/Agent/Turns/`:

| Class | What it decides or holds |
|---|---|
| `AgentRun` | one invocation: options, timings, memos, step counters, last stop reason |
| `AgentRunRelease` | the whole release of a worker turn |
| `AgentLoopStopReason` | why the loop stopped; whether it yields; whether it ends with a terminal report |
| `AIConversationTurnBudget` | step and cost budget of the current owner turn |
| `AgentLoopCorrectionState` | what the loop carries from one step to the next |
| `AgentStallClassification`, `AgentStallRecoveryOutcome` | stall shapes; the recovery's result |
| `AgentFinalTextClassification` | the facts the turn-closing decision reads |
| `AgentStepToolChoice`, `AgentStepToolRestriction`, `AgentOfferedTools` | the step's tool choice and narrowed offer |
| `AgentTurnOutcome`, `AgentReply`, `ModelResponse` | the result of `appendTurn()`, of the loop, of a model call |
| `AIConversationRunState`, `AIConversationOrigin` | named questions on the run state and on who started a conversation |
| `AIConversationDriveRequest`, `AIConversationDriveTarget`, `AIConversationDriveScope` | a drive's input, target and scope |
| `TaskPlan`, `TaskPlanItems`, `TaskPlanItem` and the `TaskPlanUpdate*` classes | the plan and its keyed updates |

## 4. Collaborators owned by other skills

| Concern | Service | Skill |
|---|---|---|
| tool dispatch, execution, follow-ups, fork-join | `AgentToolCallDispatchService`, `AgentToolCallExecutionService`, `AgentCompletedToolCallFollowUpsService`, `AgentToolCallForkJoinService` | `ddd-module-agents-tools-specialist` |
| stall recovery, final-text acceptance, turn-start decisions, skill preloads, plan dynamics, owner consent, screening | `AgentStallRecoveryService`, `AgentFinalTextAcceptanceService`, `AgentTurnStartDecisionBatchService`, `AgentSkillPreloadDecisionsService`, `AgentPlanDynamicsDecisionsService`, `OwnerConsentsService`, `AgentToolResultScreeningService` | `ddd-module-agents-decisions-and-guards-specialist` |
| purpose runs, the plan record | `AIConversationPurposeRunsService`, `AIConversationTaskPlanRecordService` | `ddd-module-agents-skills-and-purposes-specialist` |
| delegation, schedules, owner reports, supervisor | `AIConversationDelegationSpawnService`, `AIConversationDelegationResultsService`, `AIConversationDelegationNotificationsService`, `AIConversationDelegationBatchesService`, `AIConversationSchedulesService`, `AIConversationOwnerReportDeliveryService`, `SupervisorCheckpointRunsService` | `ddd-module-agents-autonomy-specialist` |
| compaction, embeddings, search | `AIConversationCompactionService`, `AIConversationMessageEmbeddingsService`, `SemanticSearchService` | `ddd-module-agents-context-specialist` |
| realtime status, activity lines | `AIConversationRealtimeSignalService`, `AgentActivityLinesService` | `ddd-module-agents-widgets-specialist` |
| turn events, traces | `AIConversationTurnEventsService`, `AITraceLogTrait` | `ddd-module-agents-observability-specialist` |
| turn-start and settled-turn listeners | `AIConversationTurnStartListenersService`, `AIConversationSettledOwnerTurnListenersService` | `ddd-module-agents-specialist` |

## 5. Drive → loop → step → release

```
driveConversation()
  persistInboundRowIndexSafe(owner row) → resumeConversationIfReady(async: true) → AIConversationResumeMessage
AIConversationResumeHandler → resumeConversationIfReady(async: false)
  claimRun() → findCompactionCoverNodes() + findContextTailMessages() → derive
  generateAgentReply() → runConversationLoop()
    per step: heartbeat() → buildOfferedToolSchemas() → performModelCallOfStep()
              → stall / checked stop / bounces → appendTurn()
                 → persistMessageUnderRun(call row PENDING) → dispatchToolCall() → follow-ups → persistMessageUnderRun(result)
    → AgentLoopStopReason (yield? terminal report?)
  compaction if due, summary if stale
  AgentRunRelease::after…() → applyRunRelease() → releaseRun() → probe → publish → re-fire → promote
  answered, nothing re-fired → runFollowUpsOfAnsweredTurn()
```

## 6. Where a new method goes

- A question about the rows of a conversation → `AIConversationMessages` or `AIConversationTurnMessages` (pure, in
  memory), not a service.
- A SQL statement over the conversation row → `AIConversationsService`; over the run columns →
  `AIConversationRunClaimsService`; over message rows → `AIConversationMessagesService`. A service never hand-writes a
  query over an aggregate another service owns.
- A pure decision → a value object with a static factory, unit-tested without a container.
- A new step concern → a new service in `Services/Conversations/Agent/` ending in `Service`, fetched with
  `getService()`; a listener, a resetter or a static library stays outside the `Services` namespaces.
- A host-replaceable behaviour → a base method a same-path subclass of the host overrides (`ddd-module-agents-specialist`,
  override points).
