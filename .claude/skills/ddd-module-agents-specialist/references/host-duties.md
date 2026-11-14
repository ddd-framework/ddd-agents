# Host duties: what the package does not ship, and what the host owes

**Reader:** host developer

Contents: the table · the shared shape of a host command · 1 fire due schedules · 2 sweep schedule state · 3 deliver
owed reports and resume owed turns · 4 reap runs whose worker died · 5 mark and reduce finalized conversations ·
6 workers · 7 base system prompt cache · 8 purge the event tables · 9 cancel a live run · the optional duties · the
cron list.

## The table

The package ships services and one console command so far, the finalize mark sweep
`AgentMarkFinalizedConversationsCommand`. Every other row is a duty of the host until the package ships it.

| Need | State in the package | Class | Start from |
|---|---|---|---|
| Fire due schedules; sweep schedule state | services exist, no command | mandatory for unattended runs | `AgentSchedulesFireCommand`, `AgentSchedulesStateSweepCommand` templates + cron lines |
| Deliver owed reports and resume owed turns | services exist, no command | mandatory | `AgentOwedWorkRecoveryCommand` template + cron line |
| Reap runs whose worker died, time out sub-agent groups past their deadline, wake parents of settled groups, settle purposed conversations closed without an owner turn, re-send lost run-end notifications of parents that run no further turn (report delivery of a crashed run depends on it) | services exist, no command | mandatory | `AgentStuckRunsReapCommand` template + cron line |
| Mark and reduce finalized conversations | the mark is a package command (`AgentMarkFinalizedConversationsCommand`, the host adds its cron line); the reduce: services exist, no command | mandatory | the package command + `AgentFinalizedConversationsReduceCommand` template (its reduce half) + cron lines |
| Workers: six transports, nine message classes, retry policies, a stop grace that lets a turn finish, resetters for the host's process state | constants and handlers exist, the host configures | mandatory | transport block template |
| The usage envelope middleware for consumed messages | no package ships one | mandatory for a host that gates budgets or attributes usage | middleware template |
| The overhead-costs middleware | no package ships one; with ddd-ai's defaults there is nothing to book | needed only together with a host cost recorder | middleware template |
| The Redis cache group | the base system prompt cache reads and writes only the Redis group | mandatory for the cache to be shared: without it the group falls back to each process's APCu, so a bump or warm-up in the web process never reaches the turn workers and a worker reads a stale prompt for up to an hour | [installation.md](installation.md) |
| Base system prompt cache: renew its inputs versions where the host writes what the prompt renders; build the queued prompts after the response | cache, version bumps and warm queue exist; the package calls neither the bumps nor the warm-up | bumps mandatory once the host's space or account answers feed the prompt; warm-up optional (latency) | section 7 |
| Purge trace and turn events past their retention (180 days), then the unreferenced turn-event snapshots | service methods exist, no caller | mandatory for a host that runs for months (the three tables grow without bound) | `AgentEventsRetentionPurgeCommand` template + cron line |
| Cancel a live run (a stop button, an external agent's cancel) | `cancelLiveRun()` exists, no route and no caller | optional | section 9 |
| Inspect or diagnose one conversation | diagnostics services and the printer exist, no command | needed to debug | `ddd-module-agents-observability-specialist` |
| Run one tool call in a child process | config key, default off (calls run inline) | optional | `ddd-module-agents-tools-specialist` |
| Strip the debug payloads of old request snapshots (`removeDebugPayloadsFromRequestSnapshots()`); rewrite legacy loaded-capability rows (`compactLoadedCapabilities()`); repair embeddings | service methods exist, no caller | optional maintenance (the message table keeps the debug payloads otherwise) | the optional duties; `ddd-module-agents-observability-specialist` (snapshots), `ddd-module-agents-context-specialist` (capability rows, embeddings) |
| Stamp the environment on old conversations (`backfillEnvironmentOnUnstampedConversations()`) | service method exists, no caller | needed once, when a host starts running several environments on one database | the optional duties |
| Prefetch the situation of spaces whose schedule fires soon (`findAgentSpacesWithRunDueBetween()`) | selector exists, no caller | optional | `ddd-module-agents-autonomy-specialist` (the selector), `ddd-module-agents-context-specialist` (what the situational context is, `DDD\Domain\AI\Services\Conversations\Situations\AgentSituationalContextService`) |
| Regenerate the Doctrine models after every Composer install and update (a host with `DATABASE_TABLE_PREFIX` or `tableNames`) | the generation command exists, no package declares a Composer script | mandatory for a host with a table prefix or own table names | [installation.md](installation.md), [tables-and-persisted-names.md](tables-and-persisted-names.md) |
| A test that reads the widgets' contract fixtures; a command that writes the composed-view schema file | the fixtures and the schema exist, no test or command for the host | needed for a host with a frontend (wire contract) | `ddd-module-agents-widgets-specialist` |
| A test that tool names are unique over every `MCPTool` method | no such test for the host | recommended (a duplicate name shadows a tool) | `ddd-module-agents-tools-specialist` |
| Realtime change signal and push (`AIConversationRealtimeSignalService::writeChangeSignal()` and its siblings) | the defaults do nothing | optional (without it the client polls) | `ddd-module-agents-widgets-specialist` |
| External agents: a protocol controller, push delivery (`dispatchTaskPushNotification()` in the realtime subclass), its transport and worker | not shipped | optional | `ddd-module-agents-widgets-specialist` |
| Recall tools for the agent (over `SemanticSearchService`, `AIConversationMessagesService::loadMessageContext()`) | the package ships no recall tool | optional; the episodic segment points the model at them | `ddd-module-agents-context-specialist` |
| An embedding provider (same-path `AITextEmbeddingsService::generateEmbeddingForText()`) | the default has none | optional (search stays keyword-only, every embedding job logs `TextEmbeddingNotGeneratedException`) | `ddd-module-agents-context-specialist` |
| Cancel queued sub-agent runs a newer request made pointless (`AIConversationDelegationBatchesService::cancelQueuedSubAgentRunsOfDeadlines()`) | method exists, no caller | optional | `ddd-module-agents-autonomy-specialist` |
| An admin endpoint or panel for the control panel and the decision statistics | the read services exist, no route | optional (needed to watch the fleet) | `ddd-module-agents-observability-specialist`, `ddd-module-agents-decisions-and-guards-specialist` |
| Send a report by e-mail; write a quality scorecard | package defaults do nothing | optional, by same-path subclass | [override-points.md](override-points.md) |
| Recover a failed settled-turn listener | exceptions are swallowed | the host's own duty when it registers one | [override-points.md](override-points.md) |
| Agent skills for tasks and schedules, settings, budget, memory, web, messages, voice | names declared, two skill files ship | the host writes its own | `ddd-module-agents-skills-and-purposes-specialist` |
| Tools that create, change or delete a schedule | the scheduled-run purpose names them, none ships | the host writes them | `ddd-module-agents-autonomy-specialist` |
| Evaluation runner, judge, suite | only the safety guard and recorded runs ship | the host builds | `ddd-module-agents-observability-specialist` |
| Frontend | the web part of the package is a placeholder | not yet | `ddd-module-agents-widgets-specialist` (wire contract) |

## The shared shape of a host command

Every template under `templates/` follows the same rules; keep them when adapting one.

- **Rights off for the scan, account per row.** A cron has no authenticated account. The command lifts the entity
  rights (`DDDService::deactivateEntityRightsRestrictions()`) and restores them in a `finally`
  (`restoreEntityRightsRestrictionsStateSnapshot()`), together with the account it found.
- **Act as the row's account.** Before a delivery or a dispatch the command sets the conversation's own account
  (`AuthService::setAccount()`): a dispatched message captures the authenticated account, and the worker runs the job
  as that account. A row without a resolvable account is skipped; keeping the previous row's account would act for
  another tenant with the rights off.
- **Bounded where the package allows it, self-clearing.** The schedule, owed-work and finalize-dispatch selectors take
  a batch limit. The reaper's selectors and the finalize mark are unbounded, which is cheap only while few runs are
  stuck. Every pass clears its own candidates, so a pass that stops early loses nothing.
- **One row never stops the pass.** Each row runs in its own `try`; a failing row is logged and, where the package
  offers it, pushed back (`deferOwedReport()`, `deferOwedTurn()`).
- **One pass at a time.** The templates use Symfony's `LockableTrait` (the framework requires `symfony/lock`) where
  two overlapping passes would load the same candidates.
- **No model call in the command.** Work that calls a model is dispatched; the worker opens its envelope. None of the
  commands needs a usage envelope of its own.
- **Environment.** The recovery and reaper selectors read only the rows of the process's environment
  (`DDD\Domain\AI\Services\Conversations\Environments\AgentEnvironmentNamesService`), so several environments on one database each run their own recovery and reaper.
  The finalize mark, its selector and the two schedule scans read every environment's rows: run those commands in
  one environment only.

**Where the called classes live.** The templates carry their `use` lines; the services the contracts below call:

| Class |
|---|
| `DDD\Domain\AI\Services\Conversations\AIConversationAgentService` |
| `DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService` |
| `DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService` |
| `DDD\Domain\AI\Services\Conversations\AIConversationMessagesService` |
| `DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService` |
| `DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose` |
| `DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService` |
| `DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService` |
| `DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService` |
| `DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService` |
| `DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService` |
| `DDD\Domain\AI\Services\Conversations\AIConversationsService` |
| `DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService` |

## 1. Fire due schedules

| | |
|---|---|
| Template | `templates/AgentSchedulesFireCommand.php` |
| When | every minute to every five minutes; a schedule fires at most one tick late |
| Calls | `AIConversationSchedulesService::findDueScheduleIds()`, per row `AIConversationSchedule::buildTransitionTo()` with `OPERATION_EXPIRE` (then `transitionStatus()` to `EXPIRED`), `claimFireOccurrence()`, `spawnRunConversationForClaimedSchedule()` |
| Account | none; the spawn runs the run as the schedule's own account |
| Envelope | none; the run is dispatched to the turn worker |
| Returns | ids (bounded by `DUE_SCHEDULES_BATCH_LIMIT`); the claim a bool (false: another runner or a pause won); the spawn an `AIConversationScheduleOccurrenceSpawn` with the run conversation or a skip reason (`OCCURRENCE_SKIP_REASON_PREVIOUS_RUN_IN_PROGRESS`, `OCCURRENCE_SKIP_REASON_BUDGET_POT_EXHAUSTED`, `OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE`, `OCCURRENCE_SKIP_REASON_AGENT_SPACE_MISSING`) |
| Without it | no schedule ever runs: timed, recurring and instant background tasks wait forever |

Two runners never fire one occurrence twice. The fire contract, the skip rules, the budget pot and the run's report
duty: `ddd-module-agents-autonomy-specialist`.

## 2. Sweep schedule state

| | |
|---|---|
| Template | `templates/AgentSchedulesStateSweepCommand.php` |
| When | hourly: the sweep is cheap (at most `STATE_SWEEP_BATCH_LIMIT` candidates, no model call), and a schedule the platform paused fires again only after the sweep re-armed it |
| Calls | `AIConversationSchedulesService::findScheduleIdsForAutomationStateSweep()`, per row `applyAutomationStateSweepToSchedule()` |
| Account | none |
| Envelope | none, no model call |
| Returns | ids (bounded by `STATE_SWEEP_BATCH_LIMIT`); per row the applied `AIConversationScheduleTransition` (`targetStatus`, `stateReason`) or null |
| Without it | a schedule of an ended scope stays ACTIVE and is refused at each fire; a schedule the platform paused is never re-armed when its scope becomes eligible again |

The candidate scan reads only the package's tables: a host whose spaces or accounts can end returns its join from
`buildAutomationStateSweepSqlFilter()` ([override-points.md](override-points.md)). Eligibility itself is the host's
same-path subclass of `DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService` (`ddd-module-agents-autonomy-specialist`).

## 3. Deliver owed reports and resume owed turns

| | |
|---|---|
| Template | `templates/AgentOwedWorkRecoveryCommand.php` |
| When | every five minutes (the quiet window `AIConversationsService::AGENT_TURN_OWED_QUIET_SECONDS` is 300 s) |
| Calls | pass 1: `findConversationIdsOwingReports()`, `AIConversationOwnerReportDeliveryService::handleReportObligationForIneligibleScope()`, `AIConversationAgentService::deliverOwedReportLate()`, on failure `deferOwedReport()`; pass 2: `findConversationIdsOwingContinuations()`, `AIConversationMessagesService::findNewestMessages()`, `AIConversation::lastWireMessageIsFinalAssistantText()` (then `clearAgentTurnOwed()`), else `resumeConversationIfReady()` with `async: true`, on failure `deferOwedTurn()`; pass 3: `findConversationIdsWithInboundLeftIdle()`, the same tail check, `hasUnansweredInboundMessage()`, `AIConversationRunClaimsService::hasLiveRunClaim()`, then `deferOwedTurn()` and `resumeConversationIfReady()` |
| Account | the conversation's own account per row |
| Envelope | none; the late report is a template, the resumed turn opens its envelope in the worker |
| Returns | ids, oldest first (pass 3: newest owner message first), bounded by `OWED_CONVERSATIONS_BATCH_LIMIT`; the delivery and resume methods return nothing and throw on failure |
| Without it | a run whose inline report delivery was lost never reports; a turn whose re-fire message was lost, or whose worker died before it claimed, or that failed under the retry cap, never answers the owner |

The delivery claims atomically, so overlapping passes never append two reports. An eval run's tree is never
selected. Run claims, release and the failed-turn rules: `ddd-module-agents-loop-specialist`; the symptom "a turn never
answers": `ddd-module-agents-observability-specialist`.

## 4. Reap runs whose worker died

| | |
|---|---|
| Template | `templates/AgentStuckRunsReapCommand.php` |
| When | every five minutes (the hung threshold `AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS` is 300 s) |
| Calls | `AIConversationsService::expireStuckConversationsPastMaxAge()`, `findStuckConversationIds()` (each: a fresh `AIConversationResumeMessage` dispatched as the conversation's account); `AIConversationDelegationBatchesService::findTimedOutUnfinishedBatchIds()` and `timeoutBatch()` (wake the returned parent); `AIConversationPurpose::getPurposeNamesClosedWithoutAnOwnerTurn()` with `findQuietIdlePurposedConversationIds()` and `AIConversationAgentService::settlePurposeConversationIfGoalReached()`; `findParentConversationIdsWithUnreadChildResults()` (wake each); `findEndedUnreportedSubAgentRunDeadlineIds()` with `AIConversationDelegationNotificationsService::notifyParentOfSubAgentRunEndOfDeadline()` |
| Account | the conversation's own account per dispatch; for a batch or a deadline its parent's |
| Envelope | none; the dispatched resumes open theirs in the worker |
| Returns | unbounded id lists: expired ids, stuck ids, timed-out group ids (`timeoutBatch()` returns the parent id to wake or null), the parents of settled but unfolded groups (the older delegation form), deadline ids; the notifier a bool |
| Without it | a run whose worker died stays RUNNING forever and the owner sees a turn that never ends; a parent waits forever on a crashed sub-agent; **the report of a crashed run is never delivered**, because the owed-report selector skips a run until this command expired it |

A fresh resume is enough to revive a stuck row; a row stuck past `AIConversationsService::RUN_REVIVE_MAX_AGE_SECONDS`
(1800 s) is expired to FAILED instead. No code of the package calls the timeout selectors: the sub-agent deadline
sweep exists only through this command. Lost run-end notifications are re-sent by the parent's own next turn
(`deliverLostNotificationsOfParent()`); for a parent that runs no further turn only this command delivers them. Run
claims and release: `ddd-module-agents-loop-specialist`; sub-agent groups and their deadlines:
`ddd-module-agents-autonomy-specialist`; which purposes settle without an owner turn:
`ddd-module-agents-skills-and-purposes-specialist`.

## 5. Mark and reduce finalized conversations

| | |
|---|---|
| Template | `templates/AgentFinalizedConversationsReduceCommand.php` |
| When | every five minutes; the idle window (60 minutes in the template) decides when a conversation counts as finished |
| Calls | `AIConversationsService::markIdleConversationsFinalized()` (dry run: `countIdleConversationsToFinalize()`), `findFinalizedConversationIdsOwingCompletionCompaction()`, per id an `AIConversationFinalizeMessage` dispatched as the conversation's account |
| Account | none for the mark; the conversation's own per dispatch |
| Envelope | none in the command; the finalize message declares `ENVELOPE_AI_CONVERSATION_COMPACTION`, which the host's envelope middleware opens; the handler lifts the budget for this maintenance work only inside that envelope |
| Returns | the number marked (one unbounded UPDATE); bare ids, bounded by the batch size |
| Without it | a finished conversation is never completion-compacted, gets no final display summary and no quality scorecard; recall and search over old conversations stay on their raw rows |

A failed job leaves no stamp, so the next pass dispatches it again. The idle rule, the finalize stamp and the
completion compaction: `ddd-module-agents-context-specialist`.

## 6. Workers

The six transports, the routing, the stop grace, the resetters and the two middlewares (what each one changes, and when
it is needed):
[transports-and-workers.md](transports-and-workers.md), templates `templates/messenger.yaml`, `templates/AgentUsageEnvelopeMiddleware.php`,
`templates/AgentConversationOverheadCostsMiddleware.php`.

## 7. Keep the base system prompt cache fresh and warm

`DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService` caches the agent's base system prompt in the Redis cache group. The package
renews no inputs version and runs no warm-up itself; its chat traits only queue a warm-up when a message is posted
(`afterMessagePosted()`), the start route queues nothing. How the key, the transaction wait and the warm marker work:
`ddd-module-agents-context-specialist`.

**7a. Renew the inputs versions (bumps)**

| | |
|---|---|
| Calls | `AgentBaseSystemPromptCacheService::bumpInputsVersionOfAgentSpace()` with the space, or `bumpInputsVersionForAccountId()` with the account id |
| When | in every host write path that changes data the prompt renders: what the host's same-path `DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService` answers for the prompt (`buildAgentSpaceContextPromptSegment()`, `buildOwnerAddressingInstruction()` and their siblings), and the account data it reads (the owner's name, the register). Data the host already returns from `findUnversionedPromptInputsOfConversation()` needs no bump |
| Account | any; the bump writes only a cache token |
| Envelope | none, no model call |
| Returns | nothing; never throws (inside an open database transaction it takes effect at the transaction's end) |
| Without it | the agent keeps reading the cached prompt built from the old data until its lifetime ends (`BASE_SYSTEM_PROMPT_TTL_SECONDS`, one hour), in every conversation of that space or account. Mandatory for a host whose space or account answers feed the prompt; a host that keeps the package's empty defaults has nothing to bump |

**7b. Build the queued prompts after the response (warm-up)**

| | |
|---|---|
| Calls | `AgentBaseSystemPromptCacheService::warmQueuedBaseSystemPrompts()` |
| When | at the end of every request, after the response was sent: a host subscriber on the kernel's terminate event, in the same process as the request that queued the warm-up |
| Account | the request's authenticated account (the one that posted the message) |
| Envelope | none; building the prompt makes no model call |
| Returns | nothing; never throws: a failed build leaves the cache as it was |
| Without it | nothing breaks: the first turn after a version bump or a changed input builds the prompt itself, on its critical path, before its first model call. Optional, a latency saving |

## 8. Purge the event tables

| | |
|---|---|
| Template | `templates/AgentEventsRetentionPurgeCommand.php` |
| When | daily |
| Calls | `AIConversationTraceEventsService::purgeOlderThanDays()`, `AIConversationTurnEventsService::purgeOlderThanDays()` (both default to `RETENTION_DAYS`, 180), then `AIConversationTurnEventsService::purgeUnreferencedSnapshots()`, always after the turn events |
| Account | none; raw statements, no rights |
| Envelope | none, no model call |
| Returns | the number of rows deleted; each purge deletes in batches on the `created` index and stops after its batch cap, so a large backlog takes several runs |
| Without it | the trace event, turn event and turn-event snapshot tables grow without bound |

## 9. Cancel a live run

| | |
|---|---|
| Calls | `AIConversationRunClaimsService::cancelLiveRun()` with the conversation |
| When | from the host's own route or tool that lets the owner (or an external agent) stop a running conversation; the chat traits have no cancel route |
| Account | the account that may act on the conversation (load it under the read rights) |
| Envelope | none, no model call |
| Returns | true when this call cancelled the run. It cancels a queued, running or delegating run, and an idle one whose latest turn is not over; it leaves a finished run alone. The sub-agent runs of the tree are cancelled with it, one change signal is published, and the conversation is closed for further input |
| Without it | nothing stops a run before it ends on its own |

The run states and the claim the cancel takes away: `ddd-module-agents-loop-specialist`.

## The optional duties

- **Child processes for parallel tool calls.** `config/app/AI/agentToolCallChildProcess.php` names a host command; its
  contract (arguments, lease, exit codes) is in the file's docblock and in `ddd-module-agents-tools-specialist`.
- **Report e-mail and scorecards.** Same-path subclasses of `DDD\Domain\AI\Services\Conversations\Agent\AgentOwnerReportEmailsService` and
  `DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService`; the report mail retries for a day, then gives up and keeps its claim.
- **Strip old request snapshots and rewrite legacy capability rows.** `AIConversationMessagesService::removeDebugPayloadsFromRequestSnapshots()`
  and `compactLoadedCapabilities()` are batched rewrites of the message table with a cursor in Redis, a time budget
  and a pause while the database is busy; every limit is a parameter the host's command passes. Without the first,
  the debug payloads of every model request stay in the message table.
- **Stamp the environment on old conversations.** A host that starts running several environments on one database
  (an override of `AgentEnvironmentNamesService`) stamps the rows written before with
  `AIConversationsService::backfillEnvironmentOnUnstampedConversations()` (dry run: `countConversationsWithoutEnvironment()`);
  until then production adopts every unstamped row.
- **A settled-turn listener's own recovery.** `DDD\Domain\AI\Listeners\Conversations\AIConversationSettledOwnerTurnListener` swallows a throw; a listener
  whose work must not be lost needs a pass of its own (for example in the owed-work command).

## The cron list

`templates/crons.sh` registers the six commands as rows of the framework's cron table with `app:crons:upsert`; the
host's system scheduler runs `app:crons:execute` every minute. The ticker gives each command at most
`Crons::TIMEOUT_PER_CRON_IN_SECONDS` (360 s), then stops it. The bounded passes stay far below that; the reaper stays
below it only while few runs are stuck.
