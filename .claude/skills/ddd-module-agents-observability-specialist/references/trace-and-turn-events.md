# Trace and turn events

**Reader:** host developer and package maintainer

Contents: three channels · turn events · the kinds · reading turn events · trace events · who wrote a row · retention · for the package maintainer · full class names

What happened around a turn that the message rows do not hold: the decisions taken, a chunk that ran, how a turn
ended, a voice call's events. The task plan's own trace is read with the plan: `ddd-module-agents-loop-specialist`.

## Three channels

| Channel | Persisted | Written by | Read by |
|---|---|---|---|
| trace log lines (`AITraceLogTrait`) | no, the logger at INFO, only with `AGENT_TRACE` on | every service and handler at its async boundaries | a developer running a worker in the foreground ([debugging-a-turn](debugging-a-turn.md)) |
| turn events (`AIConversationTurnEvent`) | yes, always | the loop, the decision sites, the release | the admin inspector, the decision statistics, the integrity report |
| trace events (`AIConversationTraceEvent`) | yes | the host's voice worker, voice controller or device endpoint — the package ships the table, the service and the readers and writes none; never the turn path | the admin inspector, the writer of each row |

## Turn events

One append-only row per event of a turn. `AIConversationTurnEventsService` is its only writer, by raw SQL:

- **idempotent** on `eventKey` (`AIConversationTurnEvent::buildEventKey()` from conversation, initiator index,
  evaluation index, kind, site and subject): a replayed write lands nothing;
- **fenced** by the run claim when the writer holds a token: a worker whose claim was taken over inserts nothing;
  the synchronous console and eval path write unfenced;
- **fail-open**: a missing table or a refused insert is logged and swallowed — except a database error inside an
  open claim-fenced message write, which must fail that write.

Columns hold what readers filter on: `aiConversationId`, `initiatorMessageIndex` (the owner message that started the
turn), `evaluationMessageIndex`, `kind`, `site`, `subject`, `requestId`, `runToken`, `arm`, `evalRun`,
`overrideSource`, `catalogSnapshotHash`, `elapsedMs`; the facts of each kind are JSON in `payload`
(`getPayloadArray()`). A decision that stores the catalog it chose from stores it once by hash
(`storeCatalogSnapshot()`, table of `AIConversationTurnEventSnapshot`).

## The kinds

| Kind | Written | Payload tells |
|---|---|---|
| `request` | per decision request at the turn start: the primary batch and every skill-preload follow-up or sub-skill pass | the sites asked, `batchId`, `decidedBy`, `transportFailureReason`, `modelVersion`; a preload pass adds `pass`, `unlockedByRootSkillName` (and the sub-skill subject). The latency is the `elapsedMs` column; no cost is written |
| `chunk` | once per invocation, right after the claim (or with the envelope id on the claim-less path) | envelope id, whether the run was claimed, whether it is a sub-agent |
| `decision` | per decision site and turn | the site's verdict; the site and its modes: `ddd-module-agents-decisions-and-guards-specialist` |
| `preloadOutcome` | never by the package: declared and read by the decision statistics only | — ; what a preload admitted is on the `decision` event of the site `skillPreload` (`pass`, `applied`, `skipReason`, `admittedSkillNames`) |
| `eviction` | when a skill or tool leaves the offer | the evicted subject |
| `forcedReDrive` | each forced tool-choice call of the stall recovery; site `stallRecovery` when the stall decision forced it, none when the ladder did; subject `step:<n>:<run token>` | `succeeded` (it produced a tool call), `forcedBy` (`stallDecision` or `ladder`), `modelName`, `toolCallCount`, its tokens, and the tokens and cost of the stalled call it replaced |
| `turnEnd` | before the release clears the token | `outcome` (`answered`, `gaveUp`, `waitingForSubAgents`, `cancelled`; `failed` only when the run ends `FAILED`; `expired` from the sweeps; `superseded` is derived on read, never stored), model calls, skill loads, nudges, the turn's cost, the turn-start timings |

`ALWAYS_ON_KINDS` (`eviction`, `forcedReDrive`, `turnEnd`, `chunk`) are written with every decision site off. A
`turnEnd` row exists only for a turn started by the owner's text or a submitted form in a root conversation — a turn
started by a signal, a sub-agent's turn and a supervisor turn have none. One row per attempt (the subject is the run
token); the newest wins.

## Reading turn events

| Question | Call |
|---|---|
| every event of a conversation, or of one owner turn | `AIConversationTurnEventsService::findForConversation($conversationId, $initiatorMessageIndex, $limit)` |
| the decision of one site for one turn | `findDecisionEvent($conversationId, $initiatorMessageIndex, $site)` |
| whether a site already decided (bounded, fail-safe: a failed read counts as decided) | `hasEventFor($conversationId, $initiatorMessageIndex, $kind, $site)` |
| the preload decisions and turn boundaries for the integrity report | `findIntegrityRecordsOfConversation($conversation)` |

Reading the turn of a silent owner message: a `chunk` without a `turnEnd` means a worker started and either did not
reach a release (it died, or it still runs) or failed below the retry cap (the release keeps the turn owed and writes
no `turnEnd`); no `chunk` at all means no worker started it ([a-turn-that-never-answers](a-turn-that-never-answers.md)).

## Trace events

`AIConversationTraceEvent` holds what shaped a conversation without being a message and must not slow the turn: the
voice provider's events (`SOURCE_VOICE_PROVIDER`), the voice worker's own events and decisions
(`SOURCE_VOICE_WORKER`), the steps of a device's call start-up (`SOURCE_DEVICE`). `AIConversationTraceEventsService`:

| Method | Does |
|---|---|
| `recordEvent($traceEvent)`, `recordEvents($traceEvents)` | batched multi-row inserts (`ROWS_PER_STATEMENT`), idempotent on `eventKey`, fail-open; returns the rows inserted |
| `attachCallEventsToConversation($agentVoiceCallId, $aiConversationId)` | links the events written before the call had a conversation |
| `findForConversation($conversationId, $limit)` | the events in time order, rights-scoped |
| `buildMessageWritersForPage($conversation, $pageMessages)` | who wrote each row of one inspector page |

A payload is capped (`PAYLOAD_MAX_BYTES`, `truncated` and `originalLength` say so), the summary line too
(`SUMMARY_MAX_LENGTH`); credential keys of a caller who is not authenticated yet are removed
(`CREDENTIAL_PAYLOAD_KEYS`). `AIConversationTraceAnomalies` is the value object a host fills with what looks wrong
in a call's trace. The voice channel itself: `ddd-module-agents-specialist` (channels).

## Who wrote a row

`AIConversationMessageWriter::classify()` derives the writer of a row on read — never stored: a voice row by the
provenance event its worker wrote (`PROVENANCE_EVENT_TYPES`), every other row by an ordered classifier (signal,
tool result, agent model, caller on the phone, owner in the chat, unknown). A voice-looking row without provenance
is classified by the same heuristic and marked `$inferred`. `AIConversationMessageWriters` is the set for one page.

## Retention

Both tables carry their own batched purge, 180 days by default (`RETENTION_DAYS`):
`AIConversationTurnEventsService::purgeOlderThanDays()` and `purgeUnreferencedSnapshots()` (a snapshot younger than
`SNAPSHOT_GRACE_HOURS` stays), `AIConversationTraceEventsService::purgeOlderThanDays()`. A host runs them from a console
command on a schedule. Both tables delete with their conversation (foreign key cascade).

## For the package maintainer

- A new turn-event kind goes into `AIConversationTurnEvent::KINDS` (and `ALWAYS_ON_KINDS` if written with the sites
  off), is written through `recordEventOfConversation()`, and keeps the writer fail-open.
- An event written during the turn-start application is collected (`collectEventsOfConversation()`) and recorded after
  the working-state write (`recordCollectedEventsOfConversation()`), so a lost claim never leaves an event without its
  slice.
- Nothing on the turn path writes trace events: a new voice or device event type goes to the trace table, a new
  loop event to the turn events.

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationMessageWriter` ·
`DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationMessageWriters` ·
`DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationTraceAnomalies` ·
`DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent` ·
`DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService` ·
`DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent` ·
`DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEventSnapshot` ·
`DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService` ·
`DDD\Domain\AI\Traits\Conversations\AITraceLogTrait`
