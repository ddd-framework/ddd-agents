# Time limits and parallel calls

**Reader:** host developer and package maintainer

Contents
- The execution ceiling
- Writing a tool that respects it
- Parallel calls of one model response
- Admission: when a tool may declare MCPToolParallelSafe
- The child-process command and the host's console script
- What the parent does around the children (package maintainer)

## The execution ceiling

| | |
|---|---|
| Default | `MCPToolExecutionTimeout::DEFAULT_SECONDS` = `AgentToolCallDispatchService::TOOL_EXECUTION_TIMEOUT_SECONDS` = 60 s |
| Own ceiling | `MCPToolExecutionTimeout(seconds, reason)` on the method or the package class; the method wins; at least 1 s; read off the loaded `ToolDefinition` (`AgentToolCallDispatchService::toolExecutionTimeoutSeconds()`) |
| Mandate tools | `MCPToolRequiresMandate` adds `CONSENT_CHECK_SECONDS` (100 s) for the consent check, unless the tool's ceiling is already `LONG_CEILING_SECONDS` (200 s) or more |
| How | in the agent loop, with pcntl available (workers, console): SIGALRM after the ceiling throws `AgentToolExecutionTimeoutException` inside whatever the tool is doing; the dispatch records a FAILED call and the model reads the message |
| Not bounded | without pcntl (a web request) and on the HTTP MCP path — the package sets no limit there |

Declare an own ceiling when the tool's honest duration is a remote job (assemble, submit, poll until done). Aborted
early, the job still completes at the remote side and the model's retry collides with it. Remember that a long
ceiling holds the turn worker: the reaper treats a run without a heartbeat for a few minutes as hung, which is why the
mandate surcharge stops at 200 s.

## Writing a tool that respects it

- A `catch (Throwable)` around a remote call must rethrow `AgentToolExecutionTimeoutException` — caught there, the
  tool would carry on without any limit.
- A tool that polls loops with a bound of its own below the ceiling and returns a state the model can act on
  ("still running — check again with …").
- A tool that only reports changing state on identical arguments declares `MCPToolRefreshesState`, or the repeat guard
  refuses its third identical call.

## Parallel calls of one model response

When a model response carries several tool calls, the loop may run some of them at once, each in a short-lived
console process of the host, while the turn worker runs the rest inline and keeps its claim on the conversation; the
results reach the model in emission order. `AgentToolCallForkJoinService::selectForkableToolCalls()` forks a call only
when ALL of this holds:

- the host declared its console script (below); without it every call runs inline;
- forking is not switched off for the process (`AgentToolCallForkJoinService::$forkingDisabled`, set by the recorded-run
  comparison);
- the call has a tool name and a provider call id, is no meta tool (`AIConversationMessage::META_TOOL_NAMES`), and its
  loaded `ToolDefinition::$isParallelSafe` is true;
- no other call of the same response calls the same tool — two calls of one tool never fork (two partial writes of
  one facet would revert each other);
- at least `MIN_FORKABLE_CALLS` (2) calls qualify — one child buys no concurrency.

Calls the loop's guards would answer without executing (repeats, reused reads, an unloaded skill) are excluded by the
caller before admission. When the response also carries an inline call that may write — not a meta tool, not forked,
not a declared read, not a tool declaring `MCPToolWritesAgentMemoryOnly` — the children start only after the inline
calls completed (`responseHasInlineWriteBesideTheFork()`).

## Admission: when a tool may declare MCPToolParallelSafe

The child loads the conversation fresh, runs the tool, writes only its own call row and exits; nothing it holds in
memory reaches the parent. Decide from the tool method AND its whole call chain (service, entity, repository, remote
call, dispatch), never from the name — a `get…` may append a widget or warm state. Do NOT declare it when the tool:

| Code | Does |
|---|---|
| W | writes a whole row another tool of the same response may write (an upsert mapped from memory writes a sibling's fresh columns back), or persists on a "read" cold path |
| C | writes a conversation row or conversation state (a widget, a message, a signal, the working state, the task plan, loaded capabilities) |
| M | is a meta tool of the loop |
| I | attaches images — a child cannot hand image bytes to the turn |
| S | claims, warms or counts per-space state a sibling could observe half-written |
| D | dispatches work whose message carries the conversation object or expects the parent's memory |
| B | depends on process-static state the parent set during the turn (the evaluation blocks are passed to the child explicitly) |

A WRITE may declare it only when every row it writes is its own — own-table rows set-level, on a shared row strictly
its own columns through a partial write, a shared JSON column under the host's per-space lock. A READ must be
idempotent. A READ that upserts a cache row of its OWN result (a remote call's cache row in the database) stays
admissible only when that upsert is idempotent and keyed by the request — say so in the `reason`. An argument that flips the verdict (a flag that also imports, or that pauses the run) disqualifies the
tool unless the tool forces the flag. The `reason` names what was audited ("one external look-up, reads only"). The
package's own `listMandates` and `getPreviousTaskRunReports` carry it.

Check the AI cost path too: a child opens its own usage envelope (next section); a budget bypass that hangs on
something only the parent process holds does not reach it.

## The child-process command and the host's console script

The package ships the command a child runs: `app:ai-agent:execute-tool-call` (`AgentExecuteToolCallCommand`, hidden
from the command list), the default of `commandName` (`AgentToolCallForkJoinService::DEFAULT_CHILD_PROCESS_COMMAND_NAME`).
The package's `config/app/AI/agentToolCallChildProcess.php` declares no `consoleScript`, so every call runs inline. A
host that wants parallel calls replaces the file whole (keys are never merged) and names `consoleScript` (relative to
the kernel project directory, e.g. bin/console). It names `commandName` only when it replaces the package's command
under a name of its own; a file without `commandName`, or with `commandName` null, gets the package's command.

The turn worker starts:

```
php -d memory_limit=1024M <project>/<consoleScript> --no-debug <commandName> <messageId> --lease=<token> --timeout=<seconds>
    [--run-token=<token>] [--blocked-tools=<a,b>] [--every-write-blocked]
```

The package's command does the following, in this order; a host command that replaces it keeps the same contract:

1. Apply `--blocked-tools` and `--every-write-blocked` to the static blocks of `MCPToolExecutorService`.
2. Load the tool-call row `<messageId>` and its conversation fresh (exit 1 when either is missing), adopt the parent's
   claim when `--run-token` is given (`AIConversationRunClaimsService::adoptRunTokenOfParentProcess()`), and
   authenticate as the CONVERSATION's account (never the console's default account), so the tool sees the rights the
   inline call sees.
3. Claim the row: `AIConversationMessagesService::claimToolCallExecutionLease($messageId, $lease)` (exit 3 when it fails).
4. Run `AgentToolCallExecutionService::executeToolCall()` under a SIGALRM of `--timeout` seconds, inside an AI usage
   envelope of its own that carries the conversation's budget-bypass grant. A replacement that runs the call without
   an open envelope gets the executor's fallback envelope for the authenticated account, and the call runs without
   the conversation's lift. Exit 5, with no outcome written, when the worker's claim on the conversation was lost.
5. Write the outcome: `AIConversationMessagesService::writeToolCallOutcomeIfLeaseHeld($message, $lease)` (exit 4 when
   refused). A failed tool is an outcome, a tool refused over the AI budget too: exit 0 after the write.

The exit codes are constants of the command: `EXIT_LEASE_NOT_CLAIMED` (3), `EXIT_OUTCOME_WRITE_REFUSED` (4),
`EXIT_RUN_CLAIM_LOST` (5).

What breaks without a console script: nothing — the calls run one after another and a batch costs the sum of their
durations.

## What the parent does around the children (package maintainer)

- `startChildrenOfFork()` settles the in-flight realtime signal first (a child must not inherit a live connection),
  starts one `Process` per call with the tool's own ceiling plus `PARENT_TIMEOUT_GRACE_SECONDS` (10 s), and writes one
  `AIConversationToolCallBatch` row for the inspector (count, deadline, run token); `recordBatchFinished()` stamps its
  `settledAt` and `consumedAt`.
- `waitForAllChildrenOfFork()` waits for every child before the first adoption — a parent persist while a child still
  writes its row fails.
- `waitForOutcome()` reads the row's `toolCall` column with one raw statement (a locking read inside an open
  transaction, so a snapshot cannot hide the child's commit), re-reads up to ten times for an exit-0 child, and
  otherwise yields a FAILED text the model reads like any tool failure; `stopAllChildren()` on an aborting turn.
- `adoptForkedToolCallOutcome()` copies the child's outcome onto the in-memory call, including the booked costs and
  the endpoint timings, and runs the inline path's post-processing (the delete-retry rule, the capability use stamps).
- The `AIConversationToolCallBatch` docblock still describes a queued design with a resume; the code uses the row only
  as the inspector's record (`AIConversationToolCallBatchesService` carries the entity binding only).
