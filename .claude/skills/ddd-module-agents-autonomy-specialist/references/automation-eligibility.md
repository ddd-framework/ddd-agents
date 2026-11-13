# Automation eligibility

**Reader:** host developer and package maintainer

## What it answers

Whether the platform may run automated AI work for an agent space or an account right now. It is asked before a
schedule fires, while the state sweep runs, before a turn is resumed, before a tool call executes, before a worker
(compaction, summary, finalize, embedding) does its job and before an owed report is delivered. It is not the budget:
a budget can be lifted for a run, eligibility cannot (see design decisions).

## The value object

`AgentAutomationEligibility`:

| Property | Meaning |
|---|---|
| `state` | `STATE_ELIGIBLE`, `STATE_SUSPENDED` (reversible), `STATE_ENDED`, `STATE_ERASED`; the most terminal signal decides |
| `reason` | the host's reason, null when eligible |
| `signalSource` | which source decided (the host's word; `SIGNAL_SOURCE_AGENT_SPACE` is the package's) |
| `resumesAutomatically` | whether the state clears by itself |
| `since`, `accountId`, `tenantAccountId` | when it began, the evaluated account, the parent account if the host has one |

`getAutomationStateReason()` maps the state to the one stamping vocabulary `AgentAutomationStateReason`
(`AGENT_SPACE_SUSPENDED`, `AGENT_SPACE_ENDED`, `ACCOUNT_SUSPENDED`, `ACCOUNT_ENDED`, `ACCOUNT_ERASED`;
`AgentAutomationStateReason::RESUMABLE` are the two suspended ones). A schedule stores it in `stateReason`, a
conversation whose owed report was closed in `suppressionReason`. `describeForModel()` is the sentence a tool shows.
A host with other reasons replaces `AgentAutomationStateReason` by its same-path class
(`getReasonClassOfTheApplication()`).

## The service and its default

`AgentAutomationEligibilityService` is the override point; there is no interface. **Every default answers "eligible",
"never refuses" and "no support override"**: a host without account states runs every automation.

| Method | Asked by |
|---|---|
| `findEligibilityForAgentSpace()`, `findEligibilityForAccountId()` | every point below; the turn start and the state sweep act on the result itself |
| `refusesAutomation()` | the refusal decision over a find result: the schedule fire step (`scheduleFire`), the workers (through `AIConversationsService::refusesAutomationForConversation()`), the report delivery (`reportDelivery`), the resume guard of a schedule; the sweep calls it only to log |
| `refusesAutomationForAgentSpace()`, `refusesAutomationForAccountId()` | the tool executor only |
| `activateSupportOverride()`, `restoreSupportOverride()`, `isSupportOverrideActive()`, `getActiveSupportOverrideNote()` | an audited override that lifts the refusal for one run; the host holds it as process state. The turn start reads its own exemption from the run settings (`supportOverride`), not from these methods |

The budget pot's display of a state is a separate override: `ScheduleBudgetPotsService::findEligibilityForDisplay()`
(default: none).

Reads that run rarely (fire, sweep, resume, report sweep) pass `useCache: false`; a host that caches eligibility must
honour it.

## What a refusal does

| Point | Outcome |
|---|---|
| schedule fire (refusal method) | no run; a recurring occurrence is refunded; no failure counted (schedules reference) |
| state sweep (on the find result) | the schedule is paused or cancelled with a stamped reason |
| tool call (`MCPToolExecutorService`, refusal method) | the call is refused before the mandate gate |
| turn start (on `isEligible()` of the find result, not a refusal method) | the run is released without a model call; a scope gone for good also stamps `suppressionReason` |
| workers (refusal method) | the job is a no-op |
| owed report (refusal method) | an ended or erased scope closes the obligation (`suppressionReason`); a suspended one defers it, at most `AIConversationOwnerReportDeliveryService::REPORT_SUPPRESSION_DEFER_CEILING_SECONDS` from the run's start |

## How a host builds it

Full class names of what a host extends or calls here: `DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService`, `DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility`, `DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService`.

1. Put a class `AgentAutomationEligibilityService` at the same path under the host's root namespace, extending the
   package class (the template in the skill's main file).
2. Read only the host's **local** states (the account, the space, a pending deletion, a local subscription row) —
   never call a billing system at fire time. Unknown states deny.
3. Override **all three** refusal methods, not only the two find methods. With only the find methods overridden, turns
   of a SUSPENDED scope are released and the sweep pauses its schedules, but schedules still fire, tools still
   execute, workers still run and report mails still go, because those points ask the refusal methods.
4. Override `buildAutomationStateSweepSqlFilter()` on the same-path `AIConversationSchedulesService` so the sweep sees
   the schedules of scopes that stopped being eligible (schedules reference).
5. Clear the host's cache when an account or space changes state, or accept the cache's lifetime as the delay.

The model call of `ddd-ai` has its own gate in an application that wants one; the package does not add one.
