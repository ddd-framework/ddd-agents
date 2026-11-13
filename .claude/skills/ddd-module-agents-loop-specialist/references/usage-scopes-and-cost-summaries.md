# Usage scopes and cost summaries

**Reader:** host developer

Contents: 1. Envelopes and scopes of the agent · 2. Who opens the envelope · 3. Budget lift on a turn · 4. Where a cost
is stored · 5. Overhead costs · 6. Cost summaries · 7. Checklist for a host

The envelope and scope machinery itself (`AIUsageEnvelopesService`, `WithAIEnvelope`, `AIUsageInitiator`,
`AIBudgetBypassGrant`, the model catalog and the budget gate) is `mgamadeus/ddd-ai`'s; this file says how the agent uses
it.

## 1. Envelopes and scopes of the agent

`AgentAIUsageScope` lists every scope the agent books under: model-call scopes (`AI_CONVERSATION_SEND_MESSAGE` — the
chat model's call of a step, declared on `ArgusAIAgentConversation`; `AI_CONVERSATION_SUMMARIZE`,
`AI_CONVERSATION_COMPACTION`, `AI_AGENT_DECIDE_TURN`, `AI_AGENT_NARRATE_ACTIVITY`, `AI_SECURITY_DETECT_INJECTION`, …) and
envelope scopes (`ENVELOPE_AI_CONVERSATION_TURN`, `ENVELOPE_AI_CONVERSATION_SUMMARIZE`, `ENVELOPE_AI_CONVERSATION_COMPACTION`,
`ENVELOPE_AI_CONVERSATION_EMBEDDING`, `ENVELOPE_AI_CONVERSATION_ACTIVITY`, `ENVELOPE_MCP_TOOL_CALL`, …). A host's own scope
catalog lists these constants beside its own scopes.

A model call outside an envelope is not grouped under one, and a budget lift granted on an envelope does nothing;
ddd-ai's default gate refuses nothing, so the call throws only when the host's own
`AIModelCallGateService::refuseModelCallWithoutUsageScopeOrEnvelope()` requires an envelope.

## 2. Who opens the envelope

| Path | Envelope |
|---|---|
| A worker turn | `AIConversationResumeMessage` carries `WithAIEnvelope` with `ENVELOPE_AI_CONVERSATION_TURN`; the middleware that opens it is a host duty (`ddd-module-agents-specialist`). Without an open envelope the handler opens its own (`AIUsageInitiator::MESSENGER`). |
| The summary job | `AIConversationSummarizeMessage` with `ENVELOPE_AI_CONVERSATION_SUMMARIZE` |
| A sync admin drive | `driveConversation()` opens `ENVELOPE_AI_CONVERSATION_TURN` itself around `generateAgentReply()` |
| A start or continue from an HTTP route | the route opens it (the chat controller traits: `openConversationEnvelopeIfNoneIsOpen()`, see `ddd-module-agents-specialist`); a host service opens it with `AIUsageEnvelopesService::openEnvelope()` and closes it in a `finally` |
| A console command of the host that drives a turn inline | the command opens it (`AIUsageInitiator::CLI`) |

## 3. Budget lift on a turn

ddd-ai's default gate checks no budget; a host whose same-path `AIModelCallGateService::refuseModelCallOverBudget()`
refuses a call over the account's budget throws `AiBudgetExceededException` (→ the loop's ERROR
row with `AiBudgetExceededException::ERROR_CODE`, a template report, the release `afterBudgetExhausted()`). A lift is a
grant on the open envelope, never a switch:

- `AIConversation::$budgetBypass` (persisted by the drive) becomes a grant with
  `AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS` for the worker turn only, restored in a `finally`
  (`grantBudgetBypassUntilRestored()` / `restoreBudgetBypassGrant()`).
- The drive itself grants for its own work when an admin operates the session
  (`AIConversationDriveRequest::decideBudgetBypass()`); a plain customer drive resets the persisted flag to false, so a
  customer never inherits an admin's lift.
- A console command grants for the process (`grantBudgetBypassForTheProcess()`) when its operator asks for it.

The spend is always booked, lifted or not.

## 4. Where a cost is stored

| What | Where |
|---|---|
| The chat model's call of a step | the step's usage stamp on its message row (`AIConversationMessage` cost and tokens; exactly one row per step) |
| What a tool booked (search fees, page reads) | the tool call's execution costs on its call row (`ddd-module-agents-tools-specialist`) |
| Everything else done for the conversation (turn-start decisions, activity lines, the summary, compaction, screening, memory) | `AIConversation::$overheadCostsInUSD` and the per-scope split `AIConversation::$overheadBookedCosts` |

`config/app/AI/usageScopesCountedElsewhere.php` lists the scopes whose costs stay out of the booked-cost recordings
(by default `AI_CONVERSATION_SEND_MESSAGE`, which the message row already holds); a host's file at the same path
replaces it whole.

## 5. Overhead costs

`generateAgentReply()` and `runConversationLoop()` record the booked costs around their own work
(`AIUsageEnvelopesService::startRecordingBookedCosts()` / `stopRecordingBookedCosts()`) and add them with
`AIConversationsService::addOverheadCostsToConversation($aiConversationId, $bookedCosts)`: ONE atomic statement that adds
the total, the booking count and one JSON path per scope (the stored form is the keyed one,
`AIUsageBookedCostsStoredForm`). A failed booking is logged as a warning and dropped; it never fails the work.

With ddd-ai's defaults these recordings stay empty: nothing calls `AIUsageEnvelopesService::recordBookedCost()` until
the host's own same-path `AIModelCallUsageRecorderService` does, so the overhead and the tool execution costs read 0.
The message row's cost comes from the model response either way.

The background jobs of a conversation implement `AIConversationOverheadCostsMessageInterface`
(`getAIConversationIdForOverheadCosts()`): the turn, the summary, compaction, finalize, the tool-result screening, the
activity lines. The package ships no code that reads it; the Messenger middleware that books those jobs' costs onto
the conversation is a host duty owned by `ddd-module-agents-specialist`.

## 6. Cost summaries

Read-only projections for an admin or reporting surface. Authorization is the caller's: the statements are scoped by
the ids given, not by read rights.

| Method | Returns |
|---|---|
| `AIConversationsService::sumConversationTreeCostsByRootConversationId($rootConversationIds)` | `AIConversationTreeCostSummaries` — per tree (the root, its delegation children, its supervisor conversation): cost including tool-call execution costs and overhead, tokens, message and conversation counts, in two queries: the message sums of all trees, then their overhead |
| `findTreeOverheadCostsInUSDByRootConversationId($rootConversationIds)` | the overhead per tree, read apart from the message sums (a join would repeat it per message) |
| `findConversationTreeCostRows($rootConversationId)` | `AIConversationCostRows` — one `AIConversationCostRow` per conversation of the tree (root first): title, cost, overhead, tokens, cached tokens, first and last message, model names |
| `findToolCallTraceForConversationTree($rootConversationId)` | `AIConversationToolCallTraceEntries` — every tool call of the root and its delegation children (not the supervisor conversation), root first, each conversation in index order, with a compact argument and result summary and its cost |

The row SQL for a message's cost including its tool's execution costs is
`DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql()`; reuse it in a host's own report so the numbers
agree.

## 7. Checklist for a host

1. List the `AgentAIUsageScope` constants in your scope catalog.
2. Envelope and overhead-costs middleware for the agent's messages: the host duties of `ddd-module-agents-specialist`.
3. Open an envelope around every host entry that drives or starts a conversation.
4. Keep your own `usageScopesCountedElsewhere.php` only if you book the chat call somewhere else.
