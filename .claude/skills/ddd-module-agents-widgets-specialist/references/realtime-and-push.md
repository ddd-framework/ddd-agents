# Realtime and push

**Reader:** host developer

Contents: a ping, never a payload · what the package ships and what the host writes · when a signal goes out · the run statuses · activity lines · traps

## A ping, never a payload

`AIConversationRealtimeSignalService::publishChangeSignal()` tells a listening client that a conversation changed. The
signal carries a change counter, the newest message id (the delta cursor of the next read) and the run status — never
message content. The client then reads the new rows through the ordinary REST reads, under the ordinary rights. The
same method also passes the change on to external agents' push configs (external agents reference). The REST run state stays
the authority: a lost ping costs latency, never correctness, so a client polls when its channel is down.

## What the package ships and what the host writes

The package has no realtime transport. These methods of `DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService` do nothing until a host
puts a same-path subclass under its own root namespace (the package resolves the service through the container, so
the subclass replaces it):

| Method | The host writes |
|---|---|
| `writeChangeSignal()` | the ping into its transport (a key per conversation, the counter, `lastMessageId`, the status); best effort — it must swallow its own failures |
| `publishSummaryUpdatedSignal()` | a ping after the conversation's title or summary was regenerated, without touching the run status |
| `settleInFlightSignal()` | wait for a response still outstanding; called before the loop forks parallel calls (a child must not inherit a live connection) and at the claim-lost exit |
| `dispatchTaskPushNotification()` | queue the push delivery of a conversation with enabled push configs |

What stays in the package: the ephemeral guard (a conversation without an id publishes nothing), the memo of the
push-config count (`AIConversationTaskPushNotificationConfigMemo`, reset per resume job and at loop start), and the
measurement the loop reads (`$lastPublishBreakdownMs`; its key names are the loop's wire names). `publishChangeSignal()` hands over
the conversation it was called with, a sub-agent's included; mapping a child's change to the root conversation the
owner watches (`AIConversation::getTreeRootConversationId()`) is the host's choice in its override. The client
needs a credential for its channel that the host mints under the conversation's read rights.

## When a signal goes out

| Moment | Status |
|---|---|
| an owner message or a signal that owes work is stored (`AIConversationTurnSignalsService::injectSignal()`) | running |
| a tool or the platform wrote a widget row — after the commit (`ConversationWidgetRowChangeSignal`, run through `AIConversationMessagesService::publishAfterCommit()`) | running |
| a row the turn wrote, once committed (`AIConversationMessagePublicationService`) | running |
| the run is released | the release's status — only when the release wrote the row (`AIConversationRunClaimsService::releaseRun()` returned true); a release that lost its claim publishes nothing, the row's owner already did |
| a run is cancelled (`AIConversationRunClaimsService::cancelLiveRun()`), after the commit | cancelled |

A publish after commit matters: a client that reads on the ping must find the row. A signal that owes no work (a render
report) publishes nothing and resumes nothing.

## The run statuses

`AIConversationRealtimeSignalService::STATUS_RUNNING`, `STATUS_IDLE`, `STATUS_FAILED`, `STATUS_CANCELLED` — lowercase
wire values, distinct from the run-state enum of the conversation. A client may read `cancelled` like `idle`: nothing
runs any more.

## Activity lines

While a turn runs, the chat may show what the agent is doing in plain sentences — never a tool name, an argument or a
result.

| Part | Class |
|---|---|
| the read | `AgentConversationReadsTrait::getAIConversationActivity()`, `GET /{aiConversationId}/activity` with `AIConversationActivityGetRequestDto` (`turnMessageId` pins the turn) → `AIConversationActivityGetResponseDto` (`turnMessageId`, `turnRunning`, `elements`) |
| the elements | `AgentActivityLinesService::buildElementsForTurn()`: one `AgentActivityLine` per tool call of the turn, from the stored call rows; some tools are silent (the skill loads, the plan bookkeeping, `findWidgets`) |
| the lines | ONE narrator call per batch (`AgentActivityLinesService::MAX_ELEMENTS_PER_NARRATOR_CALL`) through `ArgusAgentActivityLines` with the prompt `ActivityLines.md`, in the conversation's language; budget not enforced, usage scope `AgentAIUsageScope::AI_AGENT_NARRATE_ACTIVITY` |
| the cache | lines cached per element and state; a lock per conversation keeps two tabs from narrating the same elements; hourly and per-conversation ceilings |
| the push side | the client's read stamps a reader (`markReaderPresent()`, a few seconds); while a reader is stamped, every step of the turn (`recordStepForReaders()`) schedules one `AgentActivityLinesNarrateMessage` on the transport `ai_activity_lines`; `AgentActivityLinesNarrateHandler` narrates and writes the turn feed (`AgentActivityTurnFeed`) |

The details a host relies on when it builds a client or a voice reader on top:

- Every tool-call row is stored with status pending right before its own execution, so a read during a step sees it.
- An element's state comes from its call row (`AgentActivityLine::STATE_QUEUED`, `STATE_RUNNING`, `STATE_FINISHED`,
  `STATE_FAILED`; `STATE_SKIPPED` for a call refused as a duplicate or by a gate); its kind is `KIND_TOOL`,
  `KIND_DELEGATION`, `KIND_BACKGROUND`, `KIND_LONG` or `KIND_TURN_START` (the turn's opening line, always first).
  The tool calls of sub-agents join as elements with `$helperIndex`, one level deep.
- A line is cached under the key "agentActivity:line:" + version + call row id + state + language; raising
  `AgentActivityLinesService::LINE_CACHE_VERSION` after a prompt change drops every cached line.
- One narrator call takes at most `MAX_ELEMENTS_PER_NARRATOR_CALL` pending elements; the rest waits for the next read.
  After it takes the lock, the read fills the pending list again from the cache, so a second tab narrates nothing twice.
- The ceilings: `MAX_NARRATOR_CALLS_PER_CONVERSATION_AND_HOUR` narrator calls and `MAX_LINES_PER_CONVERSATION` lines.
  The narrator sees the owner's message of the turn (`MAX_CUSTOMER_MESSAGE_CHARS`), the agent's last reply
  (`MAX_ASSISTANT_SAMPLE_CHARS`), the lines already told (`MAX_ALREADY_TOLD_LINES`), and per element the arguments and
  the result head cut to `MAX_ARGUMENTS_CHARS` and `MAX_RESULT_HEAD_CHARS`, the result's directions to the agent removed
  first.
- The reader stamp (key "agentActivity:reader:" + root conversation id) lasts `READER_STAMP_TTL_SECONDS`; any reader
  may set it through `markReaderPresent()` (a voice worker of the host on its own tick). Steps are counted for the ROOT
  conversation (key "agentActivity:stepGeneration:" + root conversation id); the handler schedules itself again when steps arrived while it
  ran.
- The turn feed is cached under `AgentActivityTurnFeed::buildCacheKey()` (root conversation and turn initiator index)
  with a pointer `AgentActivityTurnFeed::buildCurrentTurnPointerKey()`; `AgentActivityLinesService::findTurnFeedForTaskAtOrAfterIndex()`
  reads it with two cache reads and no database query.
- `AgentActivityLinesService::isTurnRunning()` treats a turn as running while a run is in progress, an element is
  queued or running, or a sub-agent is not terminal — the parent conversation is idle while its sub-agents work, so the
  run state alone says nothing.

Without a reader nothing is narrated: console, eval, scheduled and replay runs never dispatch. A narrator failure
leaves the lines null; the client shows the states and the next read tries again. The narrator class names a fixed
model and endpoint; a host that uses another provider replaces it like any Argus repository of the package.

## Traps

- Content in the ping bypasses the rights of the REST read. Send ids and a status only.
- A realtime write that throws must not break the turn: catch inside `writeChangeSignal()`.
- `ai_activity_lines` needs a consumer: routed without one, the lines wait in the queue and arrive stale; left unrouted,
  every narration runs synchronously inside the step that triggered it (the entry skill's transports).
