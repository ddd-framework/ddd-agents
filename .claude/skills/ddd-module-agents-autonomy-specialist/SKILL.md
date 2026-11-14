---
name: ddd-module-agents-autonomy-specialist
description: "Explains and extends how the agent of mgamadeus/ddd-agents works without the owner present, and what it may do then. Covers delegation (delegate, check_delegations, message_sub_agent, wait_for_sub_agents, one sub-agent per call, the concurrency cap, run-end notifications, continuation runs, deadlines, background operations), schedules and unattended runs (the fire steps a host command drives, skip reasons, the Schedule.Run purpose, backoff, done criteria, time zones, the state sweep, the budget pot, the self-replication guard), automation eligibility, mandates (MCPToolRequiresMandate, one-instruction grants, grantMandate, revocation), the supervisor (off by default, steer, correct) and owner reports (send_owner_report, template reports, late delivery, the report mail). Use when the agent should delegate or run a task later, a schedule does not fire or fires twice, a sub-agent result never arrives, a tool is refused for a missing mandate, no report mail is sent, or supervision should be switched on."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents Autonomy Specialist

How the agent works when the owner is not in front of it — sub-agents, schedules and other unattended runs — and the
gates that decide what it may do then: automation eligibility, mandates, the supervisor and the report it owes the
owner afterwards.

> **Install and wire the package first** with the entry skill `ddd-module-agents-specialist`; its host-duty table
> carries the console commands and cron lines that drive the mechanisms explained here (fire due schedules, sweep
> schedule state, deliver owed reports, reap runs whose worker died).

## Scope

| Area | What this skill explains | Main classes |
|---|---|---|
| Delegation | one child conversation per `delegate` call, its record, admission, run end, notification, continuation, background operations | `AIConversationDelegationSpawnService`, `AIConversationDelegationResultsService`, `AIConversationDelegationNotificationsService`, `AIConversationDelegationBatchesService`, `DelegationSubAgentRecord` |
| Schedules | the schedule, the fire steps, the run of a schedule, the state sweep, the budget pot | `AIConversationSchedule`, `AIConversationSchedulesService`, `ScheduleRunPurpose`, `ScheduleBudgetPotsService` |
| Eligibility | whether automated work may run for an agent space or account | `AgentAutomationEligibilityService`, `AgentAutomationEligibility` |
| Mandates | standing permissions for tools the host declares as gated | `AgentMandate`, `AgentMandatesService`, `MCPToolRequiresMandate`, `AgentMandatesMCPPackage` |
| Supervisor | a second agent that watches an owner conversation | `SupervisorCheckpointService`, `SupervisorCheckpointRunsService`, `SupervisorTools`, `SupervisorPolicy` |
| Owner reports | what an unattended run owes the owner and how it reaches them | `AIConversationOwnerReportDeliveryService`, `SendOwnerReportTool`, `AgentOwnerReportEmailsService`, `AgentTaskRunReportsMCPPackage` |

Not here: the conversation tree, run claims and the release of a child's run (loop skill
`ddd-module-agents-loop-specialist`); the attribute catalogue and the meta-tool contract (tools skill
`ddd-module-agents-tools-specialist`); owner consent in an attended turn (decisions-and-guards skill
`ddd-module-agents-decisions-and-guards-specialist`); writing the purpose file of a run (skills-and-purposes skill
`ddd-module-agents-skills-and-purposes-specialist`).

## Rules that always hold

1. **Which run gets no mandate from the dialogue is decided by `scheduleId` and the child test, not by the initiator
   type.** The mandate gate refuses a grant for a call whose `AgentToolCallProvenance` carries a `scheduleId` (a
   scheduled run and its sub-agents) and for any call inside a delegation child. A root run of
   `INITIATOR_TYPE_ADMIN_TASK`, or of a host's own owner-absent type without a `scheduleId`, gets the per-request
   grant attempted from its own seed message. A host that starts such runs gives them a `scheduleId` or keeps gated
   tools out of them. The supervisor skips every run that is not owner-present (`AIConversation::canBeSupervised()`).
2. **One `delegate` call starts one sub-agent, one level deep.** Its answer is final at the spawn; the result arrives
   later as exactly one signal row per run end (`SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED`). A child never delegates.
3. **The package ships the fire command, not its cron line; the sweep is a host command.** Without the host's cron
   line for `app:ai-conversation:fire-due-schedules` no schedule fires; without the host's sweep, reaper and owed-work
   commands no deadline times out, no lost notification is re-sent and no owed report is delivered late.
4. **Eligibility is its own gate, never the budget.** Every default says "eligible"; a host with account states
   overrides `AgentAutomationEligibilityService`, including all three refusal methods.
5. **A mandate gates only a tool that carries `MCPToolRequiresMandate`.** The package tags none of its tools, and it
   derives nothing from a tool's effect. Action classes are the host's (`AgentMandate::getActionClasses()`).
6. **A scheduled run never rides a one-instruction mandate**; a deferred order needs a TIME_WINDOW mandate granted while
   the owner is present. Nothing re-checks a mandate when a schedule fires; the live gate on each gated call does.
7. **The supervisor is off** (`enabled` false in the package's `supervisor.php`), has no environment switch, and never
   watches an unattended run.
8. **No report mail leaves without a host override** of `AgentOwnerReportEmailsService`: the default sends nothing.
9. **A scheduled run is a `Schedule.Run` conversation** with one delivery path: the platform delivers the report; the
   run never mails itself and never administers schedules. The run blocks every tool that declares
   `MCPToolSendsMessageToOwner`: the host puts the attribute on its send tools. The package's purpose file names the
   host's schedule tools by fixed names; a host whose tools are named otherwise replaces the file (schedules
   reference).

## Traps

- **Overriding only the find methods of eligibility.** The turn start and the state sweep act on the find result
  itself, so turns of a SUSPENDED scope are released and its schedules paused. But the fire step, the tool executor,
  the workers and the report mail ask the refusal methods, whose default refuses nothing: schedules still fire, tools
  still execute, workers still run, mails still go. Override all three refusal methods.
- **Fire command without the expiry step.** `spawnRunConversationForClaimedSchedule()` does not expire a schedule; the
  command checks `AIConversationScheduleTransition::OPERATION_EXPIRE` before it claims.
- **Schedule tools without the provenance guard.** The package ships no schedule tools; a host's create, change and
  delete tools must refuse a call whose `AgentToolCallProvenance` carries a `scheduleId`, or a mission text with "every
  day" re-creates its schedule on every run.
- **No reaper.** A child whose worker died never ends; its parent waits until the owner writes again.
- **Reading the result from the `delegate` row's answer.** It says "started"; the result is a later signal row or
  `check_delegations`.
- **A sub-agent refused for a missing mandate.** A child is never granted one; the parent makes the first gated call of
  the request, then delegates.
- **A gated call outside a one-instruction grant's request closes that grant** before it is refused or re-granted —
  also a call of a scheduled run or of a parallel conversation, so the owner's grant in another conversation ends.
- **Supervision switched on for unattended work.** `AIConversation::canBeSupervised()` excludes it by design.
- **Editing `supervisor.php` without restarting the workers**: each process reads the file once and keeps it.

## Template: eligibility from the host's own states

A same-path subclass under the host's root namespace. The host writes the method that reads the state from its own columns;
everything else is the package's contract.

```php
<?php

declare(strict_types=1);

namespace App\Domain\AI\Services\Conversations\Eligibility;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService as BaseAgentAutomationEligibilityService;

class AgentAutomationEligibilityService extends BaseAgentAutomationEligibilityService
{
    public function findEligibilityForAgentSpace(AgentSpace $agentSpace, bool $useCache = true): AgentAutomationEligibility
    {
        $eligibility = AgentAutomationEligibility::eligible($agentSpace->accountId);
        $state = $this->findStateOfAgentSpace($agentSpace, $useCache);
        if ($state !== AgentAutomationEligibility::STATE_ELIGIBLE) {
            $eligibility->state = $state;
            $eligibility->reason = 'agentSpace:' . strtolower($state);
            $eligibility->signalSource = AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE;
            $eligibility->resumesAutomatically = $state === AgentAutomationEligibility::STATE_SUSPENDED;
        }
        return $eligibility;
    }

    public function refusesAutomation(AgentAutomationEligibility $eligibility, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        if ($eligibility->isEligible() || $this->isSupportOverrideActive()) {
            return false;
        }
        // log $operation, $eligibility->buildInspectionFields() and $logContext->buildLogFields() here
        return true;
    }

    public function refusesAutomationForAgentSpace(AgentSpace $agentSpace, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        return $this->refusesAutomation($this->findEligibilityForAgentSpace($agentSpace, false), $operation, $logContext);
    }

    public function refusesAutomationForAccountId(int $accountId, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        return $this->refusesAutomation($this->findEligibilityForAccountId($accountId, false), $operation, $logContext);
    }

    /** One of the AgentAutomationEligibility STATE_* constants, read from the host's own columns; unknown = ENDED. */
    protected function findStateOfAgentSpace(AgentSpace $agentSpace, bool $useCache): string
    {
        return AgentAutomationEligibility::STATE_ELIGIBLE;
    }
}
```

Also override `findEligibilityForAccountId()` when the host has account states, and `buildAutomationStateSweepSqlFilter()`
on a same-path `AIConversationSchedulesService` so the state sweep finds the schedules of scopes that stopped being
eligible. A support override (`activateSupportOverride()` and its siblings) is process state the host holds.

## Task or symptom → reference

**Host developer**

| Task or symptom | Reference |
|---|---|
| Let the agent or a tool start sub-agents; a sub-agent's result never arrives; "queued" sub-agents | [delegation-and-sub-agents.md](references/delegation-and-sub-agents.md) |
| Run background operations of the host after a tool | [delegation-and-sub-agents.md](references/delegation-and-sub-agents.md) |
| Run the fire command, build the sweep command; a schedule does not fire, fires twice, or never stops | [schedules-and-unattended-runs.md](references/schedules-and-unattended-runs.md) |
| Write tools that create or change schedules; cap the spend of scheduled runs | [schedules-and-unattended-runs.md](references/schedules-and-unattended-runs.md) |
| Stop automation for suspended or deleted spaces and accounts | [automation-eligibility.md](references/automation-eligibility.md) |
| Gate a tool behind a standing permission; "requires an active MANDATE" | [mandates.md](references/mandates.md) |
| Switch the supervisor on; give a purpose a supervisor policy | [supervisor.md](references/supervisor.md) |
| Send the report mail; an email run delivered but no mail left; build the owed-reports command | [owner-reports.md](references/owner-reports.md) |

**Package maintainer**

| Task or symptom | Reference |
|---|---|
| Change a writer of the sub-agent record, the notification or the deadline sweep | [delegation-and-sub-agents.md](references/delegation-and-sub-agents.md) |
| Change the fire claim, a skip reason, the backoff or the state-sweep table | [schedules-and-unattended-runs.md](references/schedules-and-unattended-runs.md) |
| Add a point that asks eligibility | [automation-eligibility.md](references/automation-eligibility.md) |
| Change the mandate gate, the one-instruction predicate or the approval check | [mandates.md](references/mandates.md) |
| Change a beat, the briefing or the acceptance of a decision | [supervisor.md](references/supervisor.md) |
| Change the report obligation, the outcome contract or the template report | [owner-reports.md](references/owner-reports.md) |
| Why it is built this way, what was rejected | [design-decisions.md](references/design-decisions.md) |

## Cross-references

- `ddd-module-agents-specialist` — installation, override points, transports, the host-duty table with the package's
  fire command and the command templates for sweeping schedule state, delivering owed reports and reaping dead runs.
- `ddd-module-agents-loop-specialist` — the conversation tree, run claims of a child, the release, recovery.
- `ddd-module-agents-tools-specialist` — `MCPToolRequiresMandate` among the tool attributes, the meta-tool contract,
  the structured-result keys a tool returns.
- `ddd-module-agents-decisions-and-guards-specialist` — owner consent before an outward action in an attended turn.
- `ddd-module-agents-skills-and-purposes-specialist` — purpose files: preloads, blocked tools, variant sections,
  `supervisorCadence`.
- `ddd-module-agents-context-specialist` — the clock and time zone the model sees.
- `ddd-module-agents-observability-specialist` — a turn that never answers, debugging a run.
