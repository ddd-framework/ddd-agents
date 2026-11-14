# Transports and workers

**Reader:** host developer

Contents: the six transports and nine messages · what the host writes · the middlewares · one job, fresh state ·
pools, limits and the stop grace · retries · a demo without workers · checking.

## The six transports and nine messages

`AgentMessengerTransports` holds the transport names (`ALL`) and the routing (`ROUTING_BY_MESSAGE_CLASS`). Every
handler binds to its transport with `AsMessageHandler(fromTransport: …)` and extends `AIDomainMessageHandler`.

| Transport (constant) | Messages | Handler limit (PHP execution seconds, memory) |
|---|---|---|
| `ai_conversation_turn` (`TURN`) | `AIConversationResumeMessage`: one agent turn | 1800 s, 1024M |
| `ai_conversation_compaction` (`COMPACTION`) | `AIConversationCompactionMessage`, `AIConversationFinalizeMessage`, `AIConversationSummarizeMessage`, `AIConversationReportEmailSendMessage` | 1800 s / 1800 s / 600 s / 300 s, 512M |
| `ai_conversation_scorecard` (`SCORECARD`) | `AIConversationScorecardMessage` | 900 s, 512M |
| `ai_conversation_message_embedding` (`MESSAGE_EMBEDDING`) | `AIConversationMessageEmbeddingMessage` | 600 s, 512M |
| `ai_tool_result_screening` (`TOOL_RESULT_SCREENING`) | `AgentToolResultScreeningMessage`: the background injection screen of an untrusted tool result; the turn never waits for it | 120 s |
| `ai_activity_lines` (`ACTIVITY_LINES`) | `AgentActivityLinesNarrateMessage`: the narration of a running turn; a stale line is worthless | 60 s |

The message classes are persisted names: a message waiting in a queue during a deploy carries its class name
([tables-and-persisted-names.md](tables-and-persisted-names.md)).

## What the host writes

The framework registers no transport and no routing for the agent. The host copies both into its `messenger.yaml`:
[templates/messenger.yaml](../templates/messenger.yaml) is the block, with the two middlewares below. A message the
host does not route runs synchronously in the dispatching process.

## The middlewares

| Middleware | Why | Without it |
|---|---|---|
| a usage envelope middleware ([templates/AgentUsageEnvelopeMiddleware.php](../templates/AgentUsageEnvelopeMiddleware.php)) | every agent message that makes model calls declares its envelope scope with `WithAIEnvelope` (ddd-ai); all but `AIConversationReportEmailSendMessage`. Neither the framework, ddd-ai nor the package ships the middleware that opens it for a consumed message. | the compaction, finalize, summary, scorecard, embedding, screening and activity handlers run their model calls outside any envelope: none is attributed to an envelope, and the handlers' budget lifts (`grantBudgetBypassIfAnEnvelopeIsOpen()`) do nothing, so a host budget gate refuses compaction, finalize, summary and screening for an account over budget. The calls throw only when the host's own `AIModelCallGateService::refuseModelCallWithoutUsageScopeOrEnvelope()` requires an open envelope; ddd-ai's default requires nothing. Only the turn handler opens a fallback envelope itself. Mandatory for a host that gates budgets or attributes usage; harmless to omit otherwise. |
| an overhead-costs middleware ([templates/AgentConversationOverheadCostsMiddleware.php](../templates/AgentConversationOverheadCostsMiddleware.php)), after the envelope middleware | a job that runs for one conversation (`AIConversationOverheadCostsMessageInterface`) books onto the conversation, through `AIConversationsService::addOverheadCostsToConversation()`, what the host's usage recorder reports with `AIUsageEnvelopesService::recordBookedCost()` while the job runs | nothing with ddd-ai's defaults: `DDD\Domain\AI\Services\AIModelCallUsageRecorderService` records nothing and nothing calls `recordBookedCost()`, so there are no amounts to book. A host that records costs (its own same-path `AIModelCallUsageRecorderService`) needs this middleware too, or the conversation's overhead cost reads too low |

The framework prepends three middlewares to every bus, each with its own opt-out parameter: fresh worker state
(`ddd.messenger.fresh_worker_state`), worker monitoring (`ddd.messenger.worker_monitoring`) and the workspace origin
guard, which refuses a consumed message another workspace dispatched (`ddd.messenger.workspace_origin_guard`). The
host lists none of them; its own middlewares run after them.

## One job, fresh state

- **One `AIConversationResumeMessage` is one turn.** Messages carry ids only; the handler restores the account the
  message carries (`setAuthAccountFromMessage()`), loads the conversation fresh and calls
  `AIConversationAgentService::resumeConversationIfReady()`. Dispatching a message captures the account that is
  authenticated at that moment: a console command dispatches as the conversation's account.
- **Fresh state per job.** Before each consumed job the framework's fresh-worker-state middleware renews the
  connections and empties the entity registries, then runs every `WorkerStateResetterInterface` service. The
  package's `AgentWorkerStateResetter` resets the agent's process state (tool blocks, decision settings of the
  process, run claims, the eval hold, the claim-fenced write state); ddd-ai's `DDD\Domain\AI\Services\AIUsageEnvelopesService` resets the
  envelopes and the budget-bypass grants. A host that keeps process state of its own writes its own resetter. A
  handler with `RESET_WORKER_STATE_BEFORE_JOB` false skips the reset; a synchronous dispatch inside a request runs no
  resetter.

## Pools, limits and the stop grace

`examples/workers.yaml` is a consumer list in the format of the framework's worker list (`config/system/workers.yaml`
of the host): replicas, `limit`, `memoryLimit` and `busySilentSeconds` per transport. Size it for the host's load.

- The handler limits are PHP execution time (`set_time_limit`): model, database and tool waits do not count toward
  them, so a job can run longer by the clock. A wall-clock limit is the host's worker configuration.
- The turn transport needs `busySilentSeconds` above the turn's 1800 s, and the process manager's **stop grace of the
  turn consumers must cover a whole turn** (1830 s in the example): Messenger stops a consumer only between two
  messages, so a deploy restart then waits for the running turn instead of killing it.
- A turn whose worker dies anyway is not lost: the reaper command takes it over ([host-duties.md](host-duties.md)).

## Retries

The turn handler rethrows only a failure of its load phase (the conversation could not be loaded), so Messenger
retries it. A failure while the turn drives is released by the loop itself (the run state, the retry count, the
owed-turn marker), and the owed-work recovery command resumes it; a Messenger retry would re-drive a half-executed
turn. One retry per transport is enough; the compaction and finalize handlers log failures and never rethrow, their
sweeps select the row again. How a turn is released: `ddd-module-agents-loop-specialist`.

## A demo without workers

The reference kernel defines the six transports with `sync://` and routes no message, so every agent message runs in
the request that dispatched it, under the envelope the controller opened. A host that routes to `sync://` instead
gets a received stamp on each message: the host's envelope and overhead-costs middlewares act as for a consumed
message, but the framework's fresh-worker-state reset and the resetters do not run (they need a worker's consumed
stamp). That is enough to try the agent; it holds the request for the whole turn.

## Checking

- `app:workers:check` compares the worker list with the transports and routing of the compiled container;
  `app:workers:list` shows the list.
- `MessengerTransportsHaveWorkerBlocksContract` (package test code, `require_once` it) checks a host's
  `messenger.yaml` and supervisor file: every transport defined, every message routed, a worker pool per transport.
- Parallel tool calls in child processes are optional and off by default (`AI.agentToolCallChildProcess`): the
  package ships the child's command, the host names its console script (`ddd-module-agents-tools-specialist`).
