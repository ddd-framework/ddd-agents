# Metrics and cost

**Reader:** host developer and package maintainer

Contents: what is stored per row · conversation metrics · tool failures and their kinds · cost reads · the scorecard worker · for the package maintainer · full class names

How long a run took and where the time went, which tools fail, and what a conversation cost — all derived from rows
the loop already writes. How a turn books its costs (usage envelopes, overhead recording, cost summaries) is the
loop's: `ddd-module-agents-loop-specialist`.

## What is stored per row

| Where | Field | Meaning |
|---|---|---|
| `AIConversationMessage` | `inputTokens`, `outputTokens`, `cachedInputTokens`, `cacheWriteTokens`, `costInUSD` | one model step |
| `ToolCall` | `durationSeconds`, `executionStartedAt`, `executionCosts`, `status` | one tool execution and the AI costs the tool booked (a search fee, a page reading) |
| `RequestSnapshot` | `loopStepOverheadMs` (`egress` = the whole provider call) | the loop's own time around a model call |
| `AIConversation` | `overheadCostsInUSD` | what a reply cost outside its rows: the decision calls, the activity lines, the sync compaction and summary |

`overheadCostsInUSD` and `executionCosts` are filled only when the host records booked costs
(`ddd-module-agents-loop-specialist`, overhead costs); with ddd-ai's defaults they stay 0. The message row's
`costInUSD` comes from the model response either way.

## Conversation metrics

`AIConversationMetricsService::buildMetricsForConversation($rootConversation)` reads the root's rows (at most
`MAX_ROWS`, truncated rows left out), the egress of its sub-agents' model calls and the intervals of the delegation
batches, and returns an `AIConversationMetrics` (null without rows) — the same numbers for an eval run and a real
owner. Every gap between two consecutive rows lands in exactly one bucket:

| Bucket | The gap ends with |
|---|---|
| `awaySeconds` | an owner row after more than `OWNER_ABSENCE_GAP_SECONDS` |
| `ownerSeconds` | an owner row within that limit, while the conversation was waiting for the owner |
| `idleSeconds` | a machine work initiator, or a long gap that neither comes from the owner nor continues the agent's work |
| `agentSeconds` | anything else: the platform was working |

Inside the agent seconds: `modelSeconds` (Σ egress of the root's calls), `toolWallSeconds` (merged blocking tool
intervals; `delegate` and `check_delegations` do not block), `delegationWaitSeconds` (merged batch intervals) and
`platformOverheadSeconds` (the rest — never negative). `childModelSeconds` is the sub-agents' cumulative model time and
may exceed the wall. Per run: `greetingLatencySeconds`, `executionTurns` with `p50TurnAgentSeconds` and
`p90TurnAgentSeconds`, `modelCalls` (and `modelCallsWithoutEgressStamp`), `toolCalls`, `toolFailures`,
`runFailures`, `pauseReports`, `checkedStopBounces`, `ownerTurns`, `ownerTextTurns`, `entryPoint`, and `toolStats`
per tool (calls, failures by kind, seconds, slowest). `upToMessageIndex` says how far the figures reach.

`AIConversationMetrics` is built through `newInstance()`: a host's same-path subclass can add its own figures.
`METRICS_VERSION` is stored with every figure set a host writes, so rows of different rules can be told apart.

## Tool failures and their kinds

A root tool call counts as failed when its own status is `FAILED`, the platform refused it (gate or duplicate) or
its result starts with one of `FAILED_TOOL_RESULT_PREFIXES`. `classifyToolCallFailure()` names the kind
(`FAILURE_KIND_*`): `loop_abort`, `repeat_corrective`, `gate_refusal`, `timeout`, `exception`, `external`,
`no_effect`, `validation`, `unknown`; `TECHNICAL_FAILURE_KINDS` are the platform's problems, the others the agent's or
by design. `external` needs the host: `EXTERNAL_PROVIDER_MARKERS` is empty in the package, and a host's same-path
subclass lists the provider names its tools put into a failure text.

## Cost reads

A run report reads the spend of a turn from the rows it already has: `AIConversationMessages::getTotalTokens()` and
`getTotalCostUsd()` before and after the turn, or per turn `AgentTurn::totalTokens()`, `totalCostUsd()` and `steps()`
of `AIConversation::buildAgentTurns()`; fleet figures come from the control panel reads
([control-panel-and-admin-drive](control-panel-and-admin-drive.md)). Per-tree cost sums and the rows behind them
(`AIConversationsService::sumConversationTreeCostsByRootConversationId()`, `findConversationTreeCostRows()`) are
explained in `ddd-module-agents-loop-specialist` (usage scopes and cost summaries).

## The scorecard worker

The chain: a host command marks idle conversations finalized (`AIConversationsService::markIdleConversationsFinalized()`)
and dispatches an `AIConversationFinalizeMessage` per conversation (the mark and the completion compaction:
`ddd-module-agents-context-specialist`); `AIConversationFinalizeHandler` runs the completion compaction and, in a
`finally` — whether the compaction succeeded or not — dispatches an `AIConversationScorecardMessage` for a root
conversation (transport `ai_conversation_scorecard`, `AgentMessengerTransports::SCORECARD`). No finalize command, no
scorecard.
`AIConversationScorecardHandler` restores the account, loads the conversation, skips an account whose AI data was
erased, and calls `AgentConversationScorecardsService::writeScorecardOfFinalizedConversation($conversation,
$forceAnalysis)`; a failure is logged, never rethrown.

| Point | Contract |
|---|---|
| Default | writes nothing, returns null |
| The host overrides it to | store the metrics (`buildMetricsForConversation()`) and its own quality assessment, keyed by the covered message index so a redelivered message writes nothing new |
| Envelope | the message declares `ENVELOPE_AI_CONVERSATION_COMPACTION`; the host's envelope middleware opens it (`ddd-module-agents-specialist`), and an assessment that calls a model runs inside it |
| `$forceAnalysis` | analyse again although the stored analysis covers the newest message (after a prompt change) |
| Without it | the worker runs and traces `scorecard.done` with nothing behind it |

A host that re-scores missed conversations dispatches the same message from a console command of its own.

## For the package maintainer

- A change of a bucket rule, a failure kind or a counted row raises `AIConversationMetrics::METRICS_VERSION`.
- A row key the metrics read is selected by `AIConversationMetricsService::findMessageRowsForConversation()`; the
  pure builder `buildFromRows()` stays free of reads so it can be tested on arrays.
- A new message type that marks owner activity or machine work belongs in `isOwnerOriginRow()` or
  `isWorkInitiatorRow()`, or the time lands in the wrong bucket.

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Entities\Conversations\AIConversation` · `DDD\Domain\AI\MessageHandlers\AIConversationFinalizeHandler`
· `DDD\Domain\AI\MessageHandlers\AIConversationFinalizeMessage` ·
`DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage` ·
`DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages` ·
`DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics` ·
`DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService` ·
`DDD\Domain\AI\MessageHandlers\AIConversationScorecardHandler` ·
`DDD\Domain\AI\MessageHandlers\AIConversationScorecardMessage` ·
`DDD\Domain\AI\Services\Conversations\AIConversationsService` ·
`DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService` ·
`DDD\Domain\AI\MessageHandlers\AgentMessengerTransports` · `DDD\Domain\AI\Entities\Conversations\Agent\AgentTurn` ·
`DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot` ·
`DDD\Domain\AI\Entities\Conversations\Messages\ToolCall`
