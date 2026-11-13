# Delegation and sub-agents

**Reader:** host developer and package maintainer

Contents: the model in short · the four tools · who can start a sub-agent · the spawn · admission and capacity · the run end · the notification · the short wait and parking · results, messages and continuations · background operations · deadlines, timeouts and lost notifications · what a host provides · tunables · traps

## The model in short

- A sub-agent is a **child `AIConversation`**: `delegatedFromMessageId` points at the `delegate` call row that started
  it, `rootConversationId` at the parent. The parent's loop and the child's loop are the same loop (why: the loop
  skill `ddd-module-agents-loop-specialist`, design decision "a sub-agent is a conversation").
- **One level only.** A child is never offered `delegate`; a child that still calls it gets
  `AIConversationDelegationSpawnService::ONE_LEVEL_REFUSAL`. A tool result inside a child starts no sub-agent and the
  platform's background requests are skipped for children. There is no depth setting.
- **One `delegate` call starts one sub-agent.** Parallel work is several `delegate` calls in one model response.
- The `delegate` row **is the sub-agent's record**: its `delegationResults` column holds one
  `DelegationSubAgentRecord` under `DelegationResults::SUB_AGENT_KEY`. Each change of state is one guarded write on one
  run of that record; after the spawn the parent never writes the column again.
- The call's answer is **final at the spawn** ("Sub-agent #3 … started (running). Its result arrives as a notification
  when it ends."). The parent's turn does not wait on the call.
- **Every run end becomes exactly one signal row in the parent** — `SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED` (resumes the
  parent) or `SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME` (rides the parent's next genuine turn). The text holds
  facts only; the model decides what to do with them. The one exception: a run cancelled together with its parent
  (`cancelledByOwner`) is stamped reported and writes no row — the parent is closed.

## The four tools

All four are built in `AgentOfferedTools` and dispatched by `AgentToolCallDispatchService`. The contract every meta
tool shares belongs to the tools skill `ddd-module-agents-tools-specialist`.

| Tool | Offered | Arguments and effect |
|---|---|---|
| `delegate` (`DelegateTool`) | every step of a root conversation | `label` (at most `AgentDelegationTask::LABEL_MAX_LENGTH` characters), and either `task` + `skill_names` (an agent task) or `toolName` + `toolArguments` (one tool run, no model). A call that names `toolName` is a tool run; its `task` text is ignored. |
| `check_delegations` (`CheckDelegationsTool`) | from the first `delegate` row on | no argument: one line per sub-agent, the newest `DelegationStatusReport::RECORDS_IN_FULL_MAX`; `subAgent` (a number or "#3"): every run of that sub-agent, its full result and the child's newest `DelegationStatusReport::DRILL_DOWN_STEPS_MAX` steps |
| `message_sub_agent` (`MessageSubAgentTool`) | beside `check_delegations` | `subAgent`, `message` (cut to `MessageSubAgentArguments::MESSAGE_MAX_LENGTH`) — see "Results, messages and continuations" |
| `wait_for_sub_agents` (`WaitForSubAgentsTool`) | only on a root turn opened by run-end notifications alone while a run that resumes the parent is unfinished | `reason`; a completed call ends the turn without text |

`wait_for_sub_agents` refuses when the owner wrote during the turn, when the turn was not opened by notifications
alone, or when no sub-agent still runs; each refusal forces a step without tools so the model answers.
`message_sub_agent` refuses inside a sub-agent and refuses the same message to the same sub-agent twice in a turn.

## Who can start a sub-agent

Three entries of `AIConversationDelegationSpawnService`, one body (`spawnSubAgent()`):

| Entry | Started by | Requested by (`AgentSubAgentRequest`) |
|---|---|---|
| `spawnSubAgentOfModelCall()` | the model's `delegate` call | `REQUESTED_BY_MODEL` |
| `spawnSubAgentsRequestedByToolResult()` | a completed tool whose structured result carries delegation keys | `REQUESTED_BY_TOOL_RESULT` |
| `spawnSubAgentsOfPlatformRequests()` | `backgroundOperations` of a tool result and the purpose's warm-ups | `REQUESTED_BY_PURPOSE_STEP` |

The last two write one synthetic `delegate` row per requested sub-agent, so every sub-agent has the same record no
matter who asked. A tool requests sub-agents through these keys of its structured result, parsed by
`AgentDelegationRequestFromToolResult::fromStructuredContent()`: `delegationTasks`, `delegationTaskLabels`,
`delegationSkillNames`, `delegationMaxStepsPerTurn` (clamped to `RunSettings::MAX_STEPS_PER_TURN_CEILING`),
`delegationModelName`, `delegationNextStepWhenAllSubAgentsEnded`, `delegationAwaitedConversationIds`,
`delegationNotifiesParentWithoutResuming`. The shape of each field (a task as a string or an object, skills per task,
labels by position) is defined once, in the loop-control reference of the tools skill
`ddd-module-agents-tools-specialist`; this reference explains what the platform does with the sub-agents they start.

Three task kinds: an agent task (`AgentDelegationAgentTask`, a model loop), a tool run (`AgentDelegationToolRunTask`,
one tool call, no model) and an awaited run (`AgentDelegationAwaitedRunTask`: a conversation that already runs is
adopted as the child; nothing is seeded, it ends through the normal run end).

`delegationNextStepWhenAllSubAgentsEnded` is stored on every record of the request and rendered once, as a "NEXT"
line, in the notification of the run end that leaves no unfinished run in that request — only when every other record
of the request has ended and been reported (`DelegationSubAgentRecord::hasCurrentRunEndedAndBeenReported()`). A
request with a `superseded` run owes no next step (`DelegationRunEndNotification::buildRequestEndedLine()`).

The spawn does not de-duplicate requests of a tool result: a tool that starts sub-agents guards itself against a second
call in the same turn, for example by asking the turn's messages (`AIConversationMessages::buildCurrentTurn()`) for an
earlier completed call of itself (`AIConversationTurnMessages::hasCompletedToolCallNamed()`).

**Awaited conversations.** The child's anchor columns are stamped once, guarded
(`AIConversationRunClaimsService::stampAwaitedConversationAsSubAgentChild()`: only while the conversation has not ended
and belongs to no other row). A worker driving it mid-turn adopts the anchor at its turn end
(`AIConversationDelegationResultsService::adoptDelegationAnchorJoinedMidTurn()`). One that had already ended ends its
run COMPLETED at once; one that belongs to another request ends FAILED with the reason.

**Purpose warm-ups.** A warm operation with `awaitGraceSeconds` above zero (`AIConversationPurposeWarmOperation`, carried
on `AgentSubAgentRequest`) makes the purpose's next step wait up to that long for it
(`AIConversationPurposeBackgroundWorkService`).

## The spawn

Before any spawn, the dispatch refuses `delegate` under a purpose that seeds its task plan, unless the next plan item
is in the purpose's closing act (`AgentToolCallDispatchService::findSeededPlanRefusal()` →
`DelegateTool::findSeededPlanRefusal()`): the call is answered as completed with `DelegateTool::answerSeededPurposeRefusal()`,
in the words of `agentApplicationWording.php` — no record, no child, no `NOT_RUN`.

`spawnSubAgent()`:

1. **Refusals of a model call** (`findRefusalOfModelRequestedTask()`): no task shape; an agent task without
   `skill_names` while the purpose restricts the loadable skills; a tool run of a tool the model is not offered; a
   tool run repeating an identical completed write or a reusable read of this turn; on the synchronous path a call
   after an earlier sub-agent of the same step failed. A refusal writes a `NOT_RUN` record (reason `refused` or
   `earlierSubAgentFailed`), marks the call FAILED and answers with the correction. No child, no deadline.
2. **Deadline row** (asynchronous path only): one `AIConversationDelegationBatch` per run,
   `AIConversationDelegationBatchesService::createSubAgentRunDeadline()`, see "Deadlines".
3. **The child** — `AIConversationDelegationChildSeedService::createAndSeedDelegationChild()`: the child inherits
   account, agent space, `rootConversationId`, budget bypass, `scheduleId`, `initiatorType`, the frozen time zone
   and the model override from the parent; its first row is the task (role USER, author AGENT); its loaded skills are
   the requested ones that pass the parent's entitlement, vision and purpose gates. A tool run gets the skill that
   provides the tool and the purpose `Background.MechanicalOperation`.
4. **The record** — `DelegationSubAgentRecord::queued()` (run 1 QUEUED) is written into the parent's `delegate` row
   inside the parent's claim-fenced write. The number (`#3`) is one above the highest number of any `delegate` row of
   the parent, reduced rows included (`AIConversationMessagesService::findNextSubAgentNumber()`). A request of several
   platform rows is admitted only once all its rows exist
   (`AIConversationDelegationSpawnService::admitAndAnswerPlatformDelegateRows()`); until then each row answers with
   `DelegationSubAgentRecord::buildAnswerTextBeforeAdmission()`.
5. **Run it.** Asynchronous: the child goes QUEUED and is admitted against the capacity (below), then an
   `AIConversationResumeMessage` is dispatched for it — on the turn transport (`AgentMessengerTransports::TURN`); a
   host that does not route it runs it synchronously in the dispatching process. Synchronous (console, eval, a drive without a run claim): the
   child runs inline in this step through `AgentChildTurnRunnerInterface::runInlineChildTurn()` (implemented by the
   loop service itself and passed in — a host never implements it unless it replaces the loop) or
   `AIConversationDelegationToolRunService::runToolRunChildTurn()` for a tool run.

The child's system prompt carries the prompt `AgentAIPromptNames::APP_AI_AGENT_SUB_AGENT_DIRECTIVE`: one level, least
work, no writing tool unless the task asks for it, an `ORCHESTRATOR_MESSAGE` signal is the parent speaking, the answer
is plain facts. A host replaces the prompt file to change it.

## Admission and capacity

`AIConversationRunClaimsService::admitQueuedDelegationChildren()` admits QUEUED children of one tree under a named lock
(`DELEGATION_CAPACITY_LOCK_PREFIX` + root id) until `DELEGATION_CONCURRENCY_CAP` slots are occupied. There is no limit
on how many sub-agents wait; the bound is the queue wait of the deadline. How the child's run is claimed and released
belongs to the loop skill `ddd-module-agents-loop-specialist`.

## The run end

The child's worker ends through `AIConversationAgentService::finishDelegationChild()` →
`AIConversationDelegationResultsService::recordSubAgentRunEnd()`, one transaction:

1. the `delegate` row locked first;
2. the child's terminal update, guarded by its run token and by the accepted-input fence: an owner row or signal newer
   than the worker's last look, or (on a completed, answered end) a waiting orchestrator message, makes the child
   yield instead of ending — it is released idle and resumed;
3. the run's end on the record: verdict (the child's last assistant text, `AIConversation::buildDelegationResultSlice()`),
   status, `childFailed` on a failed end, this run's cost;
4. the deadline row ended.

Then the freed slot is filled and the parent is notified. On the synchronous path the child ends in the same step and
the notifications are appended after the step's call rows
(`AIConversationDelegationNotificationsService::appendNotificationsOfRunsEndedInThisStep()`).

The transaction runs at READ COMMITTED; an orchestrator message the child never read is named in the run's end. On
the synchronous path a child that throws ends FAILED with `budgetReached`, `providerError` or `childFailed`, and
`reportedToParentAt` is stamped only after the notification row is stored.

**The writers.** Each state change of the record has one guarded writer on `AIConversationMessagesService`:

| Change | Writer | Guard |
|---|---|---|
| none → run 1 QUEUED, or `NOT_RUN` | `writeSubAgentRecordOfSpawn()` | the column is empty — the parent's only write |
| QUEUED → RUNNING | `writeSubAgentRunStarted()` | run k is QUEUED |
| → COMPLETED, FAILED, TIMED_OUT, CANCELLED | `writeSubAgentRunEnded()` | run k is QUEUED or RUNNING |
| → `reportedToParentAt` | `stampSubAgentRunReportedToParent()` | run k ended, not yet stamped (the exactly-once gate) |
| ended → run k+1 QUEUED | `openSubAgentContinuationRunRecord()` | run k recorded an end, run k+1 absent |

The parent never rewrites the record: `writeExistingMessageRow()` unsets `delegationResults` when it rewrites a
`delegate` row that carries a record, and restores it after the write. Readers of the current state read past the
entity registry: `findSubAgentRecordsOfConversation()` (the newest rows), `findStoredDelegateCallRow()`,
`lockStoredDelegateCallRow()` (a row lock). A run carries `failureDetail` (cut to
`DelegationSubAgentRun::FAILURE_DETAIL_MAX_LENGTH`) and `costInUsd`, this run's spend only
(`DelegationSubAgentRecord::buildCostOfCurrentRun()`).

Run statuses (`DelegationSubAgentRun`): `QUEUED`, `RUNNING`, `COMPLETED`, `FAILED`, `CANCELLED`, `TIMED_OUT`,
`NOT_RUN`; the model sees `TIMED_OUT` as FAILED. Failure reasons: `DelegationSubAgentRun::FAILURE_REASONS`.
`cancelledByOwner` has one writer: `AIConversationRunClaimsService::cancelLiveRun()`, which nothing in the package
calls — the host's cancel endpoint does; it stops the whole tree and writes no notification.

## The notification

`AIConversationDelegationNotificationsService::notifyParentOfSubAgentRunEnd()`:

- under the parent's message-index lock, in one transaction: the compare-and-set on the run's `reportedToParentAt`
  (already set = already reported, nothing written), the record re-read, the text rendered by
  `DelegationRunEndNotification::buildFor()`, the signal row written WAITING;
- after the commit, for a notification that resumes: one `AIConversationResumeMessage` when the parent is not terminal
  and holds no live claim (`resumeParentForNotification()`); a running parent reads the row at its next model call.

The text: status line, the verdict cut at `DelegationRunEndNotification::VERDICT_MAX_LENGTH` with a pointer to
`check_delegations`, "Still running: …" naming at most `DelegationRunEndNotification::STILL_RUNNING_NAMED_MAX` other
sub-agents, and the request's next step on its last run end. A failed tool run reads "FAILED: <tool> — … Nothing was
saved by it.". A record that notifies without resuming, or a run that ended `superseded`, writes the WITHOUT_RESUME
source.

After the commit a notification that resumes also stamps the parent's inbound activity and sends the realtime running
signal. The row is never merged into another row, and every release probes for waiting rows afterwards; that probe
(`AIConversationMessagesService::hasSignalWaitingThatResumesTheConversation()`) skips the WITHOUT_RESUME source.

**Hand-over and replay.** Before the next model call the loop marks waiting rows read. Run ends of one source that
landed below model output are replayed as one row, combined in index order
(`AIConversationMessages::buildCombinedSubAgentRunEndText()`). The replay keeps the meaning of what it replays
(`AIConversationSignalSource::replaySourceFor()`): `SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_REPLAY` and
`SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME_REPLAY` for run ends, `SIGNAL_SOURCE_MID_TURN_SIGNAL_REPLAY` for every
other source, owner rows included. `AIConversationTurnMessages::isOpenedBySubAgentNotificationsOnly()` reads a run-end
replay as a run end, so a run end that landed while the model call of a `wait_for_sub_agents` ran opens a notification
turn that may wait again; the loop reads its wait flag after the first step's hand-over
(`AIConversationRunClaimsService::hasUnfinishedSubAgentRunOnANotificationTurn()`).

**A turn that ends on its terminal report** (budget, step or cost stop) marks the waiting run-end rows read where they
stand (`AIConversationMessagesService::markWaitingSubAgentRunEndNotificationsReadAtTerminalTurnEnd()`, fenced on the
process's claim); the results stay on the records for `check_delegations`.

## The short wait and parking

- A root turn opened by run-end notifications alone waits before its first model call so that run ends close together
  are read in one call: `waitForCloseRunEndsBeforeTheFirstModelCall()` polls every
  `AgentSubAgentRunEndQuietWait::POLL_MICROSECONDS` until no new run end for `QUIET_MILLISECONDS`, `CAP_MILLISECONDS`
  passed, no run is unfinished or the claim was lost. Never on an owner's turn, and only when no model call ran since
  the newest turn end (`AIConversationTurnMessages::hasModelCallSinceTheNewestTurnEnd()`); it also beats the claim's
  heartbeat, and the loop reloads the rows after any wait.
- A completed `wait_for_sub_agents` is no stall, is never bounced and is not judged by the turn-closing decision;
  `AIConversationMessage::isTurnEndWithoutTextCall()` makes the walks over owed work read it as the turn's end.
- When a tool started sub-agents that resume the parent, the platform writes a completed `wait_for_sub_agents` row
  itself (`appendPlatformWaitForSubAgentsRow()`), and each later run end opens a notification turn of its own.

## Results, messages and continuations

- **Reading.** `check_delegations` renders `DelegationStatusReport::buildFor()` over the stored records plus the live
  activity of running children (`AgentSubAgentLiveActivity`).
- **`message_sub_agent`** (`AIConversationDelegationSpawnService::messageSubAgent()`): refused for an unknown number, a
  `NOT_RUN` record, a tool run, an awaited run or a record stored before one sub-agent per call
  (`DelegationSubAgentRecord::findRefusalOfMessage()`). Otherwise the message is first written into the child as an
  `ORCHESTRATOR_MESSAGE` signal (`AIConversationMessagesService::appendOrchestratorMessageToSubAgent()`), then the
  record is read under its row lock: an unfinished run reads it at its next step (an idle child is resumed); an ended
  run **continues** — `openContinuationRunOfRecord()` opens run k+1 with a new deadline, re-arms the child QUEUED
  (`AIConversationRunClaimsService::rearmTerminalDelegationChild()`) and admits it. The synchronous path continues
  inline (`continueSubAgentInline()`). There is no limit on runs per sub-agent.
- **Operators** continue a child through the same path, `openSubAgentContinuationRun()`, with `continuedBy` from the
  `DelegationSubAgentRun` `CONTINUED_BY_*` constants: `driveConversation()` reaches it for an admin's continue or edit
  and for an owner's drive of a child; a console command of the host passes its own value.

## Background operations

A completed tool may return `backgroundOperations` items (their shape: the tools skill's loop-control reference); the purpose may warm work after a step
(`AIConversationPurposeBackgroundWorkService`). Each becomes a tool-run sub-agent with the purpose
`Background.MechanicalOperation` that notifies without resuming (`AIConversationDelegationResumeCondition::BACKGROUND`
on its deadline). An operation is either `BackgroundMechanicalOperationPurpose::OPERATION_EXECUTE_TOOL` (one tool call)
or a name the host declares: override `AgentBackgroundOperationsService::getSupportedOperationNames()` and
`runMechanicalOperation()`, which returns an `AgentBackgroundOperationOutcome`, and optionally `getOperationLabel()`
(the label of the child's seed). The default supports none. Requests
are honoured only on the asynchronous path and never from inside a sub-agent.

## Deadlines, timeouts and lost notifications

- Every asynchronous run has one deadline row: timeout `AIConversationDelegationSpawnService::SUB_AGENT_RUN_TIMEOUT_SECONDS`,
  for a tool run `AIConversationDelegationToolRunService::TOOL_RUN_TIMEOUT_SECONDS` plus a minute. The child's
  `delegationBatchId` points at the row of its current run; a continuation gets a new row. The row carries
  `expectedDelegationCount` 1 and `spawnedByMessageId` = the `delegate` row; its condition is `ALL`, or
  `AIConversationDelegationResumeCondition::BACKGROUND` for a run that notifies without resuming, so
  `hasUnfinishedBatchForParent()` never counts platform work. Its end stamps `settledAt` and `foldedAt` in one
  statement, so no reader of the older group model takes it for a group with unread results. An admitted child gets a
  full window from its admission (`AIConversationRunClaimsService::extendDeadlineOfAdmittedChild()`).
- The deadline is extended while a running child's heartbeat is fresh, and while it is still queued for at most
  `AIConversationDelegationBatchesService::SUB_AGENT_QUEUED_MAX_WAIT_SECONDS`; then `timeOutSubAgentRun()` ends the
  run TIMED_OUT and notifies — one transaction, the `delegate` row locked first and the deadline re-read under the
  lock.
- `cancelQueuedSubAgentRunsOfDeadlines()` cancels runs that have not started (reason `superseded`); a host calls it
  when a newer request makes queued work pointless.
- Lost notifications (a crash between the run end and the notifier): the parent's turn head re-sends them
  (`deliverLostNotificationsOfParent()`), and a sweep finds them with `findEndedUnreportedSubAgentRunDeadlineIds()` and
  `notifyParentOfSubAgentRunEndOfDeadline()`.
- **The timeout and the lost-notification sweep run only when a console command of the host calls them**:
  `findTimedOutUnfinishedBatchIds()` → `timeoutBatch()`, and the two calls above. This is part of the host's duty to
  reap runs whose worker died (entry skill `ddd-module-agents-specialist`, host duties).
- The same reaper also re-fires parents of delegation groups stored before one sub-agent per call: `timeoutBatch()`
  returns the parent id of such a group, and `findParentConversationIdsWithUnreadChildResults()` finds parents whose
  wake was lost; the reaper dispatches a resume for each, with rights deactivated and as the parent's account.
  `AIConversationStuckRunsReadService::findStuckRuns()` shows, read-only, what each pass would act on.

## What a host provides

Full class names of what a host extends or calls here: `DDD\Domain\AI\Services\Conversations\Agent\AgentBackgroundOperationsService`, `DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService`, `DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService`, `DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService`.

- Workers for the turn transport — a sub-agent runs as its own turn (entry skill).
- The reaper command that times out deadlines, re-sends lost notifications and re-fires parents of old-style groups.
- Its cancel endpoint, if the owner may stop a running tree (`cancelLiveRun()`).
- Optionally the background operations of its own (`AgentBackgroundOperationsService` subclass at the same path).
- Optionally tools that request sub-agents through the structured-result keys above.

## Tunables

All are constants (no config key): `AIConversationRunClaimsService::DELEGATION_CONCURRENCY_CAP`,
`AIConversationDelegationSpawnService::SUB_AGENT_RUN_TIMEOUT_SECONDS`,
`AIConversationDelegationBatchesService::SUB_AGENT_QUEUED_MAX_WAIT_SECONDS`,
`SUB_AGENT_RUN_NOTIFICATION_RECOVERY_DELAY_SECONDS`, `SUB_AGENT_RUN_NOTIFICATION_RECOVERY_LOOKBACK_SECONDS`, the quiet
wait of `AgentSubAgentRunEndQuietWait`, and the text limits named above. The words in the `delegate` refusal of a
seeded-plan purpose come from `agentApplicationWording.php`.

## Traps

- Expecting the result in the `delegate` call's answer. It is in a later signal row; a test that reads the call row
  reads "started".
- Writing `delegationResults` from host code. Every transition has one guarded writer in the package.
- A host that never runs the reaper: a child whose worker died never ends, its deadline never fires, and the parent
  waits until the owner writes again.
- `AIConversationDelegationResumeCondition` values `QUORUM` and `ANY` and the `SIGNAL_SOURCE_DELEGATIONS_SETTLED` path
  serve rows stored before one sub-agent per call; new code never creates them. Such a row (a `delegationResults` map
  without the `subAgent` key, `DelegationResults::isLegacyMap()`) keeps its bytes, and its child ends through the older
  path: `AIConversationDelegationResultsService::recordDelegationChildTerminal()` →
  `AIConversationDelegationBatchesService::claimParentResumeOnChildTerminal()` →
  `resumeParentAfterChildTerminal()`, without cancelling the losers of a quorum. Only that path uses the last two.
