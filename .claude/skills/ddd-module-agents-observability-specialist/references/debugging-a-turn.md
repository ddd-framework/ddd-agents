# Debugging a turn

**Reader:** host developer

Contents: request snapshots · the full payload and raw response · the reconstructed payload · the context composition · the prompt-cache diff · tool timings · the integrity report · the delegation tree · trace log lines · the reference kernel's request log · cleaning up debug payloads · full class names

What the model saw on each call, which tools it was offered, what it answered, and where the time went. Every
reader here is a service method or a value object of the package; the host wraps them in a console command or an
admin route. Why the model input looks the way it does: `ddd-module-agents-context-specialist`.

## Request snapshots

Every assistant row of a model call carries a `RequestSnapshot` in `AIConversationMessage::$requestSnapshot`
(also the loop's empty-turn nudge, so a stall is inspectable). Always stored, small:

| Field | What it tells |
|---|---|
| `modelName`, `reasoningEffort`, `wireApi` | the model, effort and wire API of the call |
| `sentAt`, `clockAt`, `timezoneIdentifier` | when it was sent, which "now" and which zone the model was told |
| `forceToolChoice` | the forced tool choice (`none` = a tool-less answer step), null = the model chose |
| `variants`, `skillSections` | the skill variants and conditional skill sections the call was rendered with |
| `systemSegmentHashes`, `toolsHash`, `appendedToolsHash` | a hash per system segment and of the tools: the cache diff reads them |
| `epochStartIndex`, `epochCoverFingerprint`, `skillLoadingStrategy` | the prefix epoch the call was built on |
| `loopStepOverheadMs` | the loop's own milliseconds: `preCall`, `schemas`, `contextBuild`, `wire`, `egress`, `snapshot` |

A row without a request snapshot was no model call (an owner message, a tool row, a signal).

## The full payload and raw response

`sentPayloadJson` (the whole request body: system, messages, `tools`, `tool_choice`) and `rawResponseJson` (the
response before parsing) are written only while the conversation stores full snapshots —
`AIConversation::storesFullRequestSnapshots()`:

- `ContextRetentionSettings::$storeRequestSnapshot` on the conversation (an admin start passes the settings,
  `driveConversation()` stores them on a continue), or
- `RunSettings::$storeRequestSnapshot` (the measurement setting an eval runner stamps on every conversation it
  creates; delegation children read the run settings of their tree).

Switch it on **before** the call you want to see; a call already made has no payload. The raw response tells "the
model returned nothing" apart from "the model returned a call the loop failed to parse". The tool-call journal of
Argus requests (see tool timings) follows only the conversation's `ContextRetentionSettings::$storeRequestSnapshot`,
not the run setting.

## The reconstructed payload

Without the stored payload, `AIConversationModelCallsService::reconstructSentPayloadForDisplay($conversation,
$message, $baseSystemPrompt)` rebuilds the request of one assistant row: the system content composed now, the
message rows as of the snapshot's `sentAt` with the compaction cover of that moment, and the tool schemas of the
skills that row had loaded. Pass the base prompt from `resolveBaseSystemPrompt($conversation)`, or the rebuilt
system block starts at the per-call sections. The rebuild is content-faithful for the stored rows; the system prompt,
the space's context, memories and skill files may have changed since. Rebuild one message at a time, never per row
of a list. The package's printer prints it under `debug` ([console-chat-on-the-printer](console-chat-on-the-printer.md)).

The package ships the request and response DTOs of an admin route for it
(`AIConversationMessageSentPayloadGetRequestDto`, `AIConversationMessageSentPayloadGetResponseDto`), not the route:
a host's admin controller declares it and calls the method above.

## The context composition

`AIConversationMessage::buildContextComposition($conversation, $baseSystemPrompt, $openAiToolSchemas)` returns a
`ModelCallComposition`: the context of one call split into labelled `AgentContextSegment` parts (base prompt, each
loaded skill block, the compaction summary, the tool schemas, each wire message) with a token count each — the data
of a "what filled the context" view. `ModelCallComposition::unavailable()` marks a row without a snapshot;
`keepSegmentTextsOnlyForTypes()` drops the texts of the other segment types before a response leaves.
`AIConversation::buildCompactionThresholdBreakdown()` answers which compaction threshold applies and why
(`CompactionThresholdBreakdown`).

## The prompt-cache diff

`PromptCacheDiffReport::fromMessages($messages)` walks the snapshot-bearing rows in order and names, per call, where
its request first differs from the previous call's — the tools, the system content and its first changed segment,
or a wire message — with the provider's cached tokens and a cause (`CAUSE_*`: `append`, `system`, `tools`,
`toolsAppended`, `resultStub`, `ownerMessageRewrite`, …). `hitPercent()`, `$callsByCause` and `$cachedByCause` sum it
up. The tools and system causes work on every call (they compare hashes); the wire-message causes need the full
payload, so measure with full snapshots on. What busts the cache and how to keep the prefix stable:
`ddd-module-agents-context-specialist`. `app:ai-agent:cache-diff <conversationId>` prints this report per call with a
summary by cause (`--json` for the report as JSON).

## Tool timings

Each finished tool call stores `ToolCall::$durationSeconds` and, while the conversation's
`ContextRetentionSettings::$storeRequestSnapshot` is on, the Argus requests it made per endpoint (`ToolCallArgusEndpointTimings`: request count, total, maximum and span in milliseconds). Old rows
keep the keyed form `argusCallsMs`; `ToolCallArgusEndpointTimings::buildObjectFormFromStoredKeyedForm()` reads it.
The conversation metrics sum durations per tool ([metrics-and-cost](metrics-and-cost.md)).

## The integrity report

`AIConversationIntegrityReport::buildFromRecords($rows, $records, $runState)` with
`AIConversationMessagesService::findIntegrityProjectionRows()` and
`AIConversationTurnEventsService::findIntegrityRecordsOfConversation()` checks one stored conversation:

| Check | Fails when |
|---|---|
| `CHECK_PRELOAD_ON_ANCHOR` | a skill a preload decision admitted is not on a row of its turn before the first model output |
| `CHECK_NO_SAME_TURN_RELOAD` | the model loaded a skill again in the turn its preload admitted it |
| `CHECK_ADMISSION_SUCCEEDED` | a preload's write failed and rolled back |
| `CHECK_TOOL_CALLS_FINAL` | with no run active, a tool call is not in a final status |
| `CHECK_UNIQUE_TOOL_CALL_IDS` | a tool-call id appears twice |
| `CHECK_CONTIGUOUS_INDEXES` | a message index is missing |

`hasViolations()` decides the exit code: `app:ai-agent:validate-conversation` checks one or more conversations, prints
every violation and exits 1 on any.

## The delegation tree

`AIConversationDelegationTreeReport::buildFor($conversation, $batchesService)` (messages loaded) lists the child
conversations spawned by the rows with messages, tokens and cost, one block per sub-agent with each run and its
deadline row, and the older fork-join batches with their member tally. `toLlmMarkdown()` prints it.

## Trace log lines

Services and handlers that use `AITraceLogTrait` write one line per async boundary (`turn.settled`,
`scorecard.done`, …) when `AGENT_TRACE` is truthy (`1` or `true`), prefixed with
`AgentApplicationNames::getTraceLinePrefix()`. Set it inline on the command or on a worker run in the foreground
(`AGENT_TRACE=1` before `messenger:consume`, verbose); the lines go to the logger at INFO. A host that has always used
another variable name declares it in `agentApplicationNames.php`. Persisted events: [trace-and-turn-events](trace-and-turn-events.md).

## The reference kernel's request log

The reference kernel answers every model call from hand-written answers
(`ReferenceScriptedModelTransport`, installed by `ReferenceScriptedModelSubscriber`) and writes each request it
received to the folder `ReferenceScriptedModelSubscriber::REQUEST_LOG_DIRECTORY` names (below the kernel's root) as
`answered` or `unanswered`. That log is the exact body the loop sent — the
fastest way to see what the model saw while you write the first skill of a host. An unanswered request fails like an
unrecorded call; its body is what the next hand-written answer has to match.

## Cleaning up debug payloads

Full snapshots make message rows large. `AIConversationMessagesService::removeDebugPayloadsFromRequestSnapshots()`
strips `sentPayloadJson` and `rawResponseJson` from rows older than `$olderThanDays`, in primary-key windows with
short write transactions, pauses while the database is busy, stops after `$maxSeconds` and continues from a stored
cursor on the next run. It returns an `AIConversationRequestSnapshotDebugPayloadRemovalReport`
(`toReportFields()` for the command output); `$dryRun` counts without writing; an
`AIConversationProgressReporterInterface` gets one line per window. The package's command
`app:ai-agent:request-snapshot-debug-payloads-strip` runs it; on a schedule it needs a nightly cron line of the host
with `--maxSeconds` below the cron ticker's limit of 360 s (the default is 1500 s).

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Entities\Conversations\AIConversation` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationDelegationTreeReport` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityReport` ·
`DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage` ·
`DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationMessageSentPayloadGetRequestDto` ·
`DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationMessageSentPayloadGetResponseDto` ·
`DDD\Domain\AI\Services\Conversations\AIConversationMessagesService` ·
`DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService` ·
`DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface` ·
`DDD\Domain\AI\Entities\Conversations\Messages\Maintenance\AIConversationRequestSnapshotDebugPayloadRemovalReport` ·
`DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService` ·
`DDD\Domain\AI\Traits\Conversations\AITraceLogTrait` · `DDD\Domain\AI\Entities\Environment\AgentApplicationNames` ·
`DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment` ·
`DDD\Domain\AI\Entities\Conversations\Agent\Context\CompactionThresholdBreakdown` ·
`DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings` ·
`DDD\Domain\AI\Entities\Conversations\Agent\Context\ModelCallComposition` ·
`DDD\Domain\AI\Entities\Conversations\Diagnostics\PromptCacheDiffReport` ·
`DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot` ·
`DDD\Domain\AI\Entities\Conversations\Messages\RunSettings` · `DDD\Domain\AI\Entities\Conversations\Messages\ToolCall`
· `DDD\Domain\AI\Entities\Conversations\Messages\ToolCallArgusEndpointTimings`
