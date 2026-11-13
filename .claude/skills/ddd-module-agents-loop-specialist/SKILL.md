---
name: ddd-module-agents-loop-specialist
description: "Explains and changes the turn loop of mgamadeus/ddd-agents, from the owner's message to the answer. Covers the conversation tree, the drive and entry points (driveConversation, startOrReopenConversationAtEntryPoint, injectSignal, resumeConversationIfReady), AgentRun, the run claim, token and heartbeat, the release (AgentRunRelease), stop reasons, chunked execution and step and cost budgets, failed turns that stay owed, Retry, the recovery passes a host runs, claim-fenced message writes and the message-index lock, the task plan with server-minted keys, stall and final-text sites, the agent's model call, usage envelopes, overhead costs, cost summaries and the service map. Use when AgentRunClaimLostException or MessageIndexLockUnavailableException appears, a turn is driven twice, a row lands at the wrong index, a run stays RUNNING or ends FAILED, update_task_plan or set_task_status is rejected, a turn yields or hits its step budget, or when adding a release branch, a message writer or a loop step."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents Loop Specialist

How one turn of the agent runs — from the owner's message (or a signal) to the persisted answer — and how to change
that loop without breaking its exactly-once and never-stuck guarantees.

> Installation, transports, workers, override points and the host duties (the console commands a host must write):
> `ddd-module-agents-specialist`. What the model sees on each call: `ddd-module-agents-context-specialist`.

## Scope

| This skill | Another skill |
|---|---|
| conversations, the conversation tree, entry points, the drive | agent spaces, rights, start routes (`ddd-module-agents-specialist`) |
| the worker turn, `AgentRun`, the run claim, the release, recovery | the symptom table "a turn that never answers" (`ddd-module-agents-observability-specialist`) |
| the step cycle, stop reasons, budgets, chunked execution | the model input, prompt cache, context reduction (`ddd-module-agents-context-specialist`) |
| message writes, the index lock, publications | tool dispatch and execution (`ddd-module-agents-tools-specialist`) |
| the task plan entities and the two plan tools | purposes, seeded plans, done criteria (`ddd-module-agents-skills-and-purposes-specialist`) |
| stall and final-text sites in the loop | the stall recovery and final-text decisions (`ddd-module-agents-decisions-and-guards-specialist`) |
| how a run is claimed while sub-agents work | delegation itself (`ddd-module-agents-autonomy-specialist`) |
| the agent's model call, usage scopes, cost summaries | signal sources, realtime publish (`ddd-module-agents-widgets-specialist`) |

## The shape of a turn (one paragraph)

`AIConversationAgentService::driveConversation()` appends the owner's row with
`AIConversationMessagesService::persistInboundRowIndexSafe()` and, on the async path, dispatches
`AIConversationResumeMessage` (only the conversation id). `AIConversationResumeHandler` loads the conversation and
calls `resumeConversationIfReady()`, which claims the run (`AIConversationRunClaimsService::claimRun()`), loads the
compaction cover and the context tail, derives whether work is owed, and runs `generateAgentReply()` → the step loop.
Each step heartbeats, builds the offer, makes one model call, classifies a stall, checks a final text, and appends
the step's rows (`appendTurn()`). The loop returns one `AgentLoopStopReason`; the resume picks one
`AgentRunRelease` factory and `applyRunRelease()` writes it. Full walk-through: [architecture-of-a-turn](references/architecture-of-a-turn.md).

## Rules that always hold

1. **One turn = one claimed worker invocation chain.** Only a process that won `claimRun()` (one conditional UPDATE,
   fresh token) drives a turn; every other resume of the same conversation is a no-op. Never run the loop on a
   persisted conversation another worker may drive without a claim — the sync paths (`generateAgentReply()` called
   directly) are only for the console, evaluation and an IDLE admin drive.
2. **`AgentRun` is a parameter, never a field.** Build it at the entry (`AgentRun::forWorkerResume()`,
   `AgentRun::forTurnEntry()`, `AgentRun::forInlineChild()`, `AgentRun::outsideARun()`) and pass it down. New
   per-invocation state goes onto `AgentRun`, never onto a service property or a static.
3. **The run token lives in the process registry only** (`AIConversationRunClaimsService::getRunTokenClaimedInThisProcess()`);
   `AgentRun::findRunTokenClaimedInThisProcess()` reads it. Tools and purposes fence their writes on it without
   seeing the loop.
4. **A release is a factory on `AgentRunRelease`, applied by `applyRunRelease()`.** Never call `releaseRun()` by hand
   from the loop; a new branch is a new factory. The executor's order (give-up reply → turn-end event → token-guarded
   release → schedule record → probe → realtime publish → probe → re-fire → queue promotion) is load-bearing.
5. **Every row of the turn this process drives goes through `AIConversationMessagesService::persistMessageUnderRun()`**
   (or `persistNewRowUnderRunClaim()` without a run). A writer that does not drive the turn is an inbound writer
   (`persistInboundRowIndexSafe()`, `appendInboundSignal()`, `persistSignalWaitingForRunningTurn()`).
6. **Lock order: the message-index lock first, then row locks.** `openClaimFencedWrite()` takes the lock, begins the
   transaction and locks the conversation row (a locking read of `runToken`). A multi-row write that must be atomic
   opens one fenced write around all of it.
7. **Loop state is derived from persisted rows** (steps consumed, cost of the turn, bounce counts) at every step —
   a crash, a chunk yield or a claim steal reconstructs the same state. Never add a stored counter for it.
8. **A failed turn stays owed.** A transient error under `AgentRunRelease::MAX_RESUME_ATTEMPTS` releases IDLE with
   `agentTurnOwedAt` stamped; over the cap the run ends FAILED. Every error counts one attempt, also after progress.
9. **Ask run state through `AIConversation::getRunState()`** (`isClosed()`, `holdsALiveTurn()`, `isAtRestOrFailed()`, …)
   and the origin through `AIConversation::getOrigin()` — never a hand-written state or initiator list.
10. **Services through the container** (`DDDService::instance()->getService()`), database entities through
    `newInstance()`, never `new`.

## Traps

- **IDLE is neither terminal nor non-terminal.** `AIConversationRunState::NON_TERMINAL` is RUNNING, AWAITING_DELEGATES
  and QUEUED; `TERMINAL` is COMPLETED, FAILED, CANCELLED. `claimRun()` refuses terminal and QUEUED rows — a FAILED root
  is re-armed first (`AIConversationRunClaimsService::rearmRunForRetry()`).
- **A worker job inherits nothing.** `AgentWorkerStateResetter` forgets the process's run tokens and the open-write
  state before every job — except while a claim-fenced write is open (a `sync://` job inside the current one).
- **The heartbeat answers "do I still hold the claim" by row match, not affected rows** (MariaDB reports 0 changed
  rows for an unchanged second). A step-boundary heartbeat that returns false throws `AgentRunClaimLostException`;
  the loop then appends nothing more and exits silently.
- **A long tool call has no step boundary.** It keeps its run alive with
  `AIConversationRunClaimsService::touchRunningHeartbeat()`, or the reap threshold (`RUN_HUNG_AFTER_SECONDS`) lets a
  resume steal the claim.
- **A full-row write of a conversation clobbers a concurrent claim.** Persist single columns
  (`updatePartialIgnoringRights()`), JSON slices (`persistAgentWorkingStateSlice()`, `persistTaskPlanSlice()`) or the
  atomic increment (`addOverheadCostsToConversation()`).
- **PostgreSQL differs:** the message-index lock lives for one transaction, and a refused INSERT ends the transaction,
  so the free-index retry has no second attempt there. Code that depends on a retry after a duplicate breaks on it.
- **A worker reads stale entities.** A long-lived consumer must load with `find()` and the entity registry cache off (`useEntityRegistrCache` false) —
  the handler does; a new handler must too.
- **The loop never calls a model in a budget-exhausted run** after `AiBudgetExceededException`: the report is
  composed from templates only (a model call would throw again).

## Minimal template — start a conversation from an entry point of the host

A host service that opens (or reopens, within six hours) the conversation behind one product entry point. The
conversation works for an `AgentSpace`, or at account level when null. The start runs inside a usage envelope, as
the reference kernel's start route does (`ReferenceConversationsController`), so any model call on the way is booked.

```php
<?php

declare(strict_types=1);

namespace App\Domain\Demo\Services;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\EntryPoints\AIConversationEntryPointStart;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

class DemoNotesConversationStartsService extends Service
{
    /** Opaque to the package: letters, digits and `. _ : -`, at most 255 characters. */
    public const string NOTES_ENTRY_POINT_KEY = 'demo.notes.start';

    public function startOrReopenNotesConversation(?AgentSpace $agentSpace, string $ownerText): AIConversationEntryPointStart
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        $turnEnvelope = $aIUsageEnvelopesService->openEnvelope(
            AuthService::instance()->getAccount()?->id,
            AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN
        );
        try {
            // customer scope, always async: the answer arrives through the worker and the realtime signal
            return $aIConversationAgentService->startOrReopenConversationAtEntryPoint(self::NOTES_ENTRY_POINT_KEY, $agentSpace, $ownerText);
        } finally {
            $aIUsageEnvelopesService->closeEnvelope($turnEnvelope);
        }
    }
}
```

`reopenedExisting` on the result says that nothing was appended and no turn started. For a plain continue, call
`driveConversation()` with `AIConversationDriveScope::CUSTOMER`; for a fact that should wake the agent, call
`AIConversationTurnSignalsService::injectSignal()`.

## Task or symptom → reference

### Host developer

| Task or symptom | Reference |
|---|---|
| What happens between the owner's message and the answer; sync vs async drives; entry points | [architecture-of-a-turn](references/architecture-of-a-turn.md) |
| `AgentRunClaimLostException`, a resume that does nothing, a run stuck RUNNING, FAILED with Retry, what the recovery passes must do | [run-claims-release-and-recovery](references/run-claims-release-and-recovery.md) |
| Writing a row into a conversation from host code, `MessageIndexLockUnavailableException`, a duplicate index | [message-writes](references/message-writes.md) |
| `update_task_plan` / `set_task_status` rejected, a turn that yields, the step or cost budget | [task-plan-and-long-tasks](references/task-plan-and-long-tasks.md) |
| Booking usage under the right scope, overhead costs of a background job, the cost of a conversation tree | [usage-scopes-and-cost-summaries](references/usage-scopes-and-cost-summaries.md) |
| A turn that never answers (symptom → cause) | `ddd-module-agents-observability-specialist` |

### Package maintainer

| Task | Reference |
|---|---|
| Which service owns a responsibility, where a new method goes | [service-map](references/service-map.md) |
| Adding a loop step, a stop reason, a release branch | [architecture-of-a-turn](references/architecture-of-a-turn.md), [run-claims-release-and-recovery](references/run-claims-release-and-recovery.md) |
| Adding a message writer or a fenced multi-row write | [message-writes](references/message-writes.md) |
| Stall shapes, the checked stop, the bounces and retries before a final text | [stall-and-final-text](references/stall-and-final-text.md) |
| Changing a plan rule (keys, tick gates, budgets) | [task-plan-and-long-tasks](references/task-plan-and-long-tasks.md) |
| Why the loop is built this way, and what would change a decision | [design-decisions](references/design-decisions.md) |

## Cross-references

- `ddd-module-agents-specialist` — installation, transports and workers, override points, the host duties with the
  templates for the recovery commands.
- `ddd-module-agents-context-specialist` — the model input, compaction, skill and tool eviction.
- `ddd-module-agents-tools-specialist` — tool dispatch, execution, time limits, the closed owner question.
- `ddd-module-agents-skills-and-purposes-specialist` — purposes, seeded task plans, done criteria, the plan record.
- `ddd-module-agents-autonomy-specialist` — delegation, schedules, owner reports, the supervisor.
- `ddd-module-agents-decisions-and-guards-specialist` — the decision sites (stall recovery, turn closing), screening.
- `ddd-module-agents-widgets-specialist` — signal sources, realtime and push.
- `ddd-module-agents-observability-specialist` — diagnosing a turn, turn events, traces, record and replay.
- mgamadeus/ddd-ai — `AIUsageEnvelopesService`, `AIBudgetBypassGrant`, the model catalog.
