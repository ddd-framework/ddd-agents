# Schedules and unattended runs

**Reader:** host developer and package maintainer

Contents: what runs without the owner · the schedule · the fire contract · the run of a schedule · after the run · the state sweep · the budget pot and cost estimates · time zones · limits and the self-replication guard · writing the tool that creates a schedule · what a host provides · traps

## What runs without the owner

`AIConversation::getOwnerAbsentInitiatorTypes()` lists the initiator types of runs the platform starts without the
owner: `INITIATOR_TYPE_SCHEDULE` (one firing of a schedule) and `INITIATOR_TYPE_ADMIN_TASK` by default. A host that
starts runs of its own declares further `INITIATOR_TYPE_*` constants on its same-path conversation class and redeclares
the list (entry skill `ddd-module-agents-specialist`). Sub-agents of such a run inherit `initiatorType` and
`scheduleId`. What such a run may do is decided by the mandate gate (mandates reference), the eligibility gate
(automation-eligibility reference) and the purpose of the run.

## The schedule

`AIConversationSchedule` (one row per task set up):

| Group | Properties |
|---|---|
| owner and scope | `accountId` (rights and budget), `agentSpaceId` (empty = account level; written once at creation) |
| content | `title`, `task` (the seed of every run), `doneCriteria` |
| timing | `schedule` (a cron expression) or `oneTimeAt` (exactly one is set), `timezone`, `timezoneUnverified`, `nextRunAt` (the fire anchor), `lastFiredAt` |
| quota | `iterationsUsed`, `maxIterations`, `ttlAt` |
| health | `failureStreak`, `lastFailedAt`, `stateReason` |
| delivery | `reportChannel` (`REPORT_CHANNEL_CHAT` or `REPORT_CHANNEL_EMAIL`), `runSettings`, `perRunCostCapUsd` |

Statuses (`AIConversationScheduleStatus`): `ACTIVE`, `PAUSED`, `EXPIRED`, `DONE`, `CANCELLED`, `ARCHIVED`. Every
legality question — may it be paused, resumed, edited, fired now, expired, closed by the sweep — is one call,
`AIConversationSchedule::buildTransitionTo()`, decided by `AIConversationScheduleTransition` (operations
`OPERATION_*`, refusals `REFUSAL_*`). Use it instead of comparing statuses in host code.

`AIConversationSchedulesService::createSchedule()` stamps the owner account, checks the agent space
(`AgentSpacesService::requireAgentSpaceUsableByAccount()`), sets ACTIVE, fills the zone and computes `nextRunAt`.
`persistScheduleEdit()` re-arms the anchor when the cron, the zone or the one-time moment changed.
`deleteScheduleFollowingArchivePolicy()` hard-deletes a schedule that never ran, archives a recurring one that ran and
keeps a one-time one that ran. `AIConversationScheduleOneTimeMoment::resolveFor()` turns "in N minutes" or a local
"YYYY-MM-DD HH:MM" into the moment, moving a day-scale wish out of the night.

## The fire contract

The package's command `app:ai-conversation:fire-due-schedules` (`AgentFireDueSchedulesCommand`) runs these steps, one
pass at a time (a process lock; the claim alone already prevents a double fire). It runs only from the host's cron
line, every minute to every five minutes (the entry skill's host duties). A host that replaces it keeps the steps:

1. `findDueScheduleIds()` — ACTIVE rows with `nextRunAt` due, oldest first, at most
   `AIConversationSchedulesService::DUE_SCHEDULES_BATCH_LIMIT`.
2. Load each one fresh (no registry cache); skip it when it is no longer active.
3. **Expiry is the caller's step:** when `buildTransitionTo(AIConversationScheduleTransition::OPERATION_EXPIRE, now)`
   is allowed (TTL passed or quota used), `transitionStatus()` to `EXPIRED` and do not fire.
4. `claimFireOccurrence()` — one compare-and-set on `nextRunAt`: it moves the anchor to the next occurrence (with the
   failure backoff when `failureStreak` is above zero), stamps `lastFiredAt` and counts `iterationsUsed`. False = another
   process or a concurrent pause won; skip.
5. `spawnRunConversationForClaimedSchedule()` → `AIConversationScheduleOccurrenceSpawn` (a run, or a skip reason):

| Check, in order | Outcome |
|---|---|
| the newest run of the schedule is still in progress | `OCCURRENCE_SKIP_REASON_PREVIOUS_RUN_IN_PROGRESS`; nothing refunded |
| the agent space is gone (or a schedule without one is not allowed: `AgentSpacesService::isScheduleWithoutAgentSpaceAllowed()`, default allowed — see the agent-space reference of the entry skill `ddd-module-agents-specialist`) | the schedule is closed CANCELLED with `AGENT_SPACE_ENDED`; `OCCURRENCE_SKIP_REASON_AGENT_SPACE_MISSING` |
| eligibility, read fresh, refuses `scheduleFire` | recurring: the occurrence is refunded; `OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE` |
| the budget pot is exhausted | one notice per month to the owner; one-time: EXPIRED, recurring: refunded; `OCCURRENCE_SKIP_REASON_BUDGET_POT_EXHAUSTED` |
| otherwise | the run conversation is started asynchronously |

The package does not catch an exception of the spawn: the command catches per schedule and goes on with the next.
`refundClaimedOccurrence()` gives a claimed occurrence back (`reArmNow: true` fires it again at once, for an
infrastructure failure).

Manual fires go through the same tail: `fireScheduleNow()` (a recurring schedule, at most once per hour),
`startScheduleNow()` (a due one-time schedule that never fired), `fireEvalScheduleNow()` (eval schedules only).

**Prefetching for runs that fire soon.** `findAgentSpacesWithRunDueBetween()` returns each (account, agent space)
pair that holds an ACTIVE schedule with `nextRunAt` inside a time window, once, as `AIConversationScheduleDueAgentSpace`
(`accountId`, `agentSpaceId`); schedules without a space are left out. It reads across accounts and spaces, so its
caller runs in a console context with rights deactivated. No package code calls it: it exists for a console command of
the host that warms what a run will read — for example the situational context its same-path
`AgentSituationalContextService` builds — shortly before the run fires, acting as each pair's account. The host duty is
listed in the entry skill `ddd-module-agents-specialist`.

## The run of a schedule

The run is started by `AIConversationAgentService::driveConversation()` with the drive scope
`AIConversationDriveScope::ADMIN`, `initiatorType` `INITIATOR_TYPE_SCHEDULE`, the `scheduleId`, the schedule's account
as the run's account, the run settings of the schedule (the per-run cost cap folded in) and the purpose `Schedule.Run`
with the schedule's `reportChannel`.

- **The seed** is the owner's mission only: `AIConversationSchedule::composeSeedUserMessageText()` — the task text plus
  a "Last run (…) result:" line from the previous run's stored summary (never a model call to build one).
- **The purpose** `ScheduleRunPurpose` carries the role ("you are the run that executes the mission, never schedule
  anything"), nobody present (no question to the owner), and the delivery contract per report channel
  (`ScheduleRunPurpose::DIMENSION_REPORT_CHANNEL`, one section for EMAIL and one for CHAT). It preloads
  `Assistant.TaskRunReports` and blocks the owner-messaging send tools of the host for the run and its sub-agents, so
  the report has one delivery path. How a purpose file declares preloads, blocked tools and variant sections belongs to
  the skills-and-purposes skill `ddd-module-agents-skills-and-purposes-specialist`.
- **Tools a run blocks, and tool names in the package's purpose file.** A run blocks every tool that declares
  `MCPToolSendsMessageToOwner`, whatever the host names it (`ScheduleRunPurpose::getToolNamesBlockedByDeclaration()`);
  a host send tool without the declaration stays callable inside a run and is a second delivery path. The text of
  `config/app/AI/Purposes/Schedule/Run.md` names four schedule tools the run must not call
  (scheduleOneTimeTask, createRecurringTaskSchedule, updateTaskSchedule, deleteTaskSchedule); it also tells the model
  to call `send_owner_report` and `getPreviousTaskRunReports`, which the package ships. None of the four schedule
  tools ships with the package. A host whose schedule tools carry these names keeps the file; any other host replaces
  the purpose file with its own at the same config path.
- **The time zone** of the schedule is frozen on the run; its sub-agents inherit it.
- **The run closes** once its report reached its channel: `ScheduleRunPurpose::shouldCloseConversationAfterSettledTurn()`
  holds for a CHAT run when the turn settled, for an EMAIL run once `reportDeliveredAt` is set (owner reports
  reference). A closed run accepts no further owner input.

## After the run

The loop calls `AIConversationSchedulesService::recordRunTerminalOfConversation()` at the run's end (no-op without
`scheduleId`):

- `recordRunTerminal()` — success resets `failureStreak`, failure counts it and stamps `lastFailedAt`; the backoff skips
  2^streak − 1 occurrences, capped.
- An owner notice in the conversation that created the schedule: always on success, on failure from the second failure
  in a row; skipped while that conversation runs or holds an unanswered owner message.
- A one-time schedule goes DONE.
- On success with `doneCriteria`: `transitionToDoneWhenDoneCriteriaFulfilled()` asks `ArgusScheduleDoneCriteriaJudgment`
  (prompt `AgentAIPromptNames::APP_AI_SCHEDULE_DONE_CRITERIA_JUDGMENT`, a fast model, temperature 0, JSON) whether the
  run's last text fulfils the criteria; fulfilled → DONE. An unreadable answer changes nothing; the next run asks again.
- A run whose report the owed-reports command delivers late as a template also counts as a failed run of its schedule
  (owner reports reference).

## The state sweep

A console command of the host (its cadence is set in the entry skill's host duties) closes schedules whose scope stopped being eligible and re-arms those the platform
paused. Again the package has the steps:

1. `findScheduleIdsForAutomationStateSweep()` — ACTIVE or PAUSED rows with a `stateReason`, plus what the host's
   `buildAutomationStateSweepSqlFilter()` adds (default: nothing — then only platform-paused rows are seen). A host
   overrides that method on its same-path `AIConversationSchedulesService` to join its own account and space tables.
2. `applyAutomationStateSweepToSchedule()` per row (`dryRun` available) — eligibility read fresh, the pure decision
   `AIConversationSchedule::decideTransitionForAutomationState()`:

| Row | Scope | Result |
|---|---|---|
| ACTIVE | ENDED or ERASED | CANCELLED with the reason; its runs cancelled and their owed reports closed |
| ACTIVE | SUSPENDED | PAUSED with the reason |
| PAUSED | ENDED or ERASED | CANCELLED with the reason |
| PAUSED by the owner (no reason) | SUSPENDED or ELIGIBLE | untouched |
| PAUSED by the platform (reason set) | ELIGIBLE | ACTIVE, reason cleared, next firing from now (a one-time row whose moment passed: EXPIRED) |

`transitionStatusForAutomationState()` is the only writer of `stateReason`. `assertScheduleMayBeResumed()` refuses an
owner's resume of a platform-paused row and of a row whose scope is not eligible.

## The budget pot and cost estimates

- `ScheduleBudgetPotsService::findScheduleBudgetPotStatusForAccount()` → `ScheduleBudgetPotStatus`: the pot is the
  monthly budget times the share the host grants scheduled runs; the spend is the month-to-date cost of conversations
  with a `scheduleId` (sub-agents included) of the accounts that pool their budget. Exhausted = pot above zero and spend
  at or above it.
- **The package default is no budget**: monthly budget 0 and share 0 — a zero pot that never caps. A host overrides
  `ScheduleBudgetPotsService` at the same path (monthly budget, share, pooled accounts, whether exact figures are hidden).
- Under a budget-bypass grant the pot is a zero pot that never caps, and runs with `budgetBypass` do not count toward
  the spend.
- The pot is enforced only at the fire step. `buildScheduleBudgetPotStatusForAccount()` is a read for tools and screens
  that answers null on an error.
- `buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpace()` (and the account-level twin) estimate the month
  of the active recurring schedules: firings in 30 days times the average cost per run (the schedule's own, else the
  space's, else an assumed one). Information for a tool that creates a schedule; nothing enforces it.

## Time zones

The cron digits are local time of the schedule's own `timezone` (`getCronTimezoneIdentifier()`, UTC when empty). The
next occurrence, the backoff and `countCronFiringsWithinDays()` all use it; a repeated autumn hour fires once
(`isRepeatedLocalOccurrence()`), and a local time inside the skipped spring hour fires once at the next existing local
time, as the cron library places it (02:30 fires at 03:30 on that day). A new
schedule takes its zone from `AgentSpacesService::findTimezoneForNewSchedule()`. How the run's clock is shown to the
model belongs to the context skill `ddd-module-agents-context-specialist`.

## Limits and the self-replication guard

- `AIConversationScheduleCreationLimits` holds the limits a schedule-creating tool applies (active schedules per space,
  cron firings per day, recurring schedules created per day, runs per schedule, title length). `createSchedule()` does
  not enforce them; the tool does.
- **A scheduled run never administers schedules.** The purpose says so, and every tool call of the run and of its
  sub-agents carries the `scheduleId` in `AgentToolCallProvenance`. The package ships no schedule tools: a host's
  create, change, pause, resume and delete tools must refuse a call whose provenance has a `scheduleId` — otherwise a
  mission text that contains "every day at 12:00" re-creates its own schedule on every run. Starting a one-off
  background task is execution, not administration, and may stay allowed.

## Writing the tool that creates a schedule

The package ships no schedule tool; the host writes one per action in its own MCP package. A creating tool:

1. **Refuses inside a scheduled run.** When the call's `AgentToolCallProvenance::$scheduleId` is set (a scheduled run or
   one of its sub-agents), it creates nothing and answers that a run executes its task and never schedules.
2. **Builds the row** with `AIConversationSchedule::newInstance()` — never `new`, so the host's same-path subclass is
   the one stored — and fills:

| Property | What the tool puts in |
|---|---|
| `title` | the owner-facing label, cut with `AIConversationScheduleCreationLimits::cutTitle()` (at most `AIConversationScheduleCreationLimits::TITLE_MAX_CHARS`) |
| `task` | the mission only — the text every run is seeded with; no timing words (the timing lives in the next two fields) |
| `oneTimeAt` or `schedule` | exactly one: a moment (from `AIConversationScheduleOneTimeMoment::resolveFor()`, which turns "in N minutes" or a local "YYYY-MM-DD HH:MM" into it), or a cron expression in local time |
| `reportChannel` | `AIConversationSchedule::REPORT_CHANNEL_CHAT` (default) or `REPORT_CHANNEL_EMAIL`, normalized with `AIConversationScheduleCreationLimits::normalizeReportChannel()` |
| `initiatorType` | `AIConversationSchedule::INITIATOR_TYPE_AGENT` for a schedule the agent creates (the default is `INITIATOR_TYPE_ADMIN`, which makes the owner of the space the owning account — meant for an admin console); `INITIATOR_TYPE_USER` for an owner's own screen |
| `createdByConversationId` | the owner's thread: `AgentToolCallProvenance::getTreeRootConversationIdForToolCall()` — the run notices go there |
| `agentSpaceId` | the call's space, `AgentToolCallProvenance::$agentSpaceId`; empty = a schedule at account level |
| `timezone` | optional: the zone the call thinks in (`AgentToolCallProvenance::getTimezone()`), else the space's zone is filled in |
| `doneCriteria`, `maxIterations`, `ttlAt`, `perRunCostCapUsd` | optional: when the schedule ends by itself, and a cost cap per run |

3. **Applies the creation limits** of `AIConversationScheduleCreationLimits` — the package does not enforce them:
   `isActiveScheduleCapReached()` over the space's active schedules (`findSchedulesOfAgentSpace()`), for a recurring
   schedule `isDailyRecurringCreationCapReached()` over `countAgentRecurringSchedulesCreatedForAgentSpaceSince()` from
   `getStartOfCreationDay()`, `exceedsCronFiringsPerDay()` over `countCronFiringsPerDay()`, and
   `isMaxRunsWithinRange()` for a run count.
4. **Calls `AIConversationSchedulesService::createSchedule()`**, which decides the owning account (the authenticated
   account, except for `INITIATOR_TYPE_ADMIN`), checks the space, sets ACTIVE, fills the zone and computes `nextRunAt`;
   it refuses a one-time row that also carries a cron and an invalid cron (`BadRequestException`).
5. **Echoes the next firing** with its offset, so the owner sees the local moment.

```php
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleCreationLimits;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;

    protected function createRecurringScheduleOfToolCall(
        string $title,
        string $task,
        string $cronExpression,
        string $reportChannel,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AIConversationSchedule {
        if ($agentToolCallProvenance->scheduleId !== null) {
            throw new BadRequestException('A scheduled run executes its task; it never creates a schedule.');
        }
        $schedule = AIConversationSchedule::newInstance();
        $schedule->initiatorType = AIConversationSchedule::INITIATOR_TYPE_AGENT;
        $schedule->createdByConversationId = $agentToolCallProvenance->getTreeRootConversationIdForToolCall();
        $schedule->agentSpaceId = $agentToolCallProvenance->agentSpaceId;
        $schedule->title = AIConversationScheduleCreationLimits::cutTitle(trim($title));
        $schedule->task = $task;
        $schedule->schedule = $cronExpression;
        $schedule->reportChannel = AIConversationScheduleCreationLimits::normalizeReportChannel($reportChannel);
        $timezone = $agentToolCallProvenance->getTimezone();
        if ($timezone !== null) {
            $schedule->timezone = $timezone->getName();
        }
        $firingsPerDay = AIConversationScheduleCreationLimits::countCronFiringsPerDay($schedule, new DateTime());
        if (AIConversationScheduleCreationLimits::exceedsCronFiringsPerDay($firingsPerDay)) {
            throw new BadRequestException('The schedule fires too often in one day.');
        }
        /** @var AIConversationSchedulesService $schedulesService */
        $schedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        return $schedulesService->createSchedule($schedule);
    }
```

**Tool names the package's run purpose expects.** `config/app/AI/Purposes/Schedule/Run.md` tells a scheduled run
that the tools named scheduleOneTimeTask, createRecurringTaskSchedule, updateTaskSchedule and deleteTaskSchedule are
refused inside a run. A host that names its tools so keeps the file; otherwise it replaces the purpose file (see "The
run of a schedule"). The refusal itself is always the tool's own (step 1). The tools that send the owner a message
are blocked by their declaration `MCPToolSendsMessageToOwner`, not by name: the host puts the attribute on each of
its send tools.

## What a host provides

Full class names of what a host extends or calls here: `DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService`, `DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService`, `DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule`.

- The cron line of the package's fire command, and the state-sweep command — template, cron lines and cadence in the
  entry skill's host duties.
- The tools that create, list, change, pause, resume and delete schedules, with the provenance guard above.
- Optionally: the budget pot (`ScheduleBudgetPotsService`), eligibility (`AgentAutomationEligibilityService`), the
  sweep filter, the report mail (owner reports reference).

## Traps

- Firing without the expiry step: a schedule over its quota keeps being claimed.
- Resuming with `transitionStatus()` alone. Call `assertScheduleMayBeResumed()` first: without it a platform-paused row
  goes ACTIVE with its `stateReason` still set — the owner's resume skips the platform-pause rule, and the row stays a
  candidate of every state sweep. (The fire step still asks eligibility, so an ended scope does not run.)
- Raw statements of the schedule service use backtick quoting; check them on PostgreSQL before relying on a host there.
