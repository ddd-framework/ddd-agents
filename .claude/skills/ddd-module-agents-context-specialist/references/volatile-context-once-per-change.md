# Volatile context delivered once per change

**Reader:** host developer and package maintainer

Contents: 1. The switch · 2. State epochs · 3. The epoch record · 4. Notes · 5. What still rides every call ·
6. Host contract · 7. Adding a kind

## 1. The switch

`ContextRetentionSettings::$stateChangeDelivery`:

- `everyCall` — every volatile value (the space's context text, the owner addressing, the zone, the standing
  directives, the product blocks, the situation provenance) is rendered fresh on every call; a change rewrites the
  prefix from where the value sits.
- `onceWhenChanged` (the package config's value) — the values are frozen per **state epoch** and every change reaches
  the model once, as a `[SIGNAL:<kind>]` note in the history.

Stamped at creation, frozen after the first model call, inherited by sub-agents, works under both skill-loading
strategies. Refused together with the server-state wire arm (`AgentWireConfiguration::REJECTION_SERVER_STATE_WITH_STATE_NOTES`).

## 2. State epochs

`AgentStateEpoch::decideForNewCall()` decides the epoch of a call about to be sent. A NEW epoch starts:

| Cause constant | When |
|---|---|
| `CAUSE_CONVERSATION_START` | no model call in the raw history yet |
| `CAUSE_COMPACTION` | a new compaction cover (the fingerprint changed, or the recorded start lies behind the edge) |
| `CAUSE_COLD_PREFIX` | the newest model call was sent `COLD_AFTER_MINUTES` (30) or more ago — its cached prefix is cold anyway |
| `CAUSE_SURFACE_CHANGE` | who reads the call on which surface changed (`AgentModelInput::buildStateDeliverySurfaceKey()`: presence, phone or screen, kit taught or not) |
| `CAUSE_RECORD_MISSING` | the newest call recorded no epoch, or its record is not in the context |

Otherwise the call continues the newest call's epoch. A new epoch starts after the newest model-call row (or at the
cover edge if later), so nothing an earlier epoch recorded renders in it. The 30-minute rule is checked on every call:
a step after a tool that ran 30 minutes opens an epoch mid-turn. Under `conversationAppend` the state epoch's start is
handed to the prefix epoch: the old epoch's appended skills join the base set
([prompt-cache-and-stable-prefix.md](prompt-cache-and-stable-prefix.md)), and the conditional meta tools the old
epoch appended (`AgentPrefixEpoch::$carriedConditionalMetaToolNames`) close the new epoch's top-level tools with their
static schemas, in a fixed order, offered now or not: rows of the history still call them, and a call must never
come before its tool's declaration.

Every call records `RequestSnapshot::$stateEpochStartIndex`, `$stateEpochCause`, `$stateEpochCoverFingerprint`; a
rebuild reads the recorded epoch (`AgentStateEpoch::readForRecordedCall()`), never re-derives it from timestamps.
Deciding costs two linear scans over the raw tail already in memory (the newest model-call row, then the epoch's
record), no query.

## 3. The epoch record

The epoch's first call stores the values as of the start (`AgentConversationState`, on
`RequestSnapshot::$stateAtEpochStart`). For the whole epoch:

- the base prompt is a template whose `{%AGENT_SPACE_CONTEXT%}` and `{%OWNER_ADDRESSING%}` the record fills
  (`AgentModelInput::fillStateDeliveryPlaceholdersOf()`);
- the `# Time` zone, the standing directives and the three product blocks render from the record;
- each owner bracket renders in the zone in effect at its position (`AgentModelInput::resolveTimezoneForMessageIndex()`).

A value the run could not resolve is an unknown kind (`AgentConversationState::$unknownKinds`): the system content
omits it, never shows a literal placeholder, and its first resolved value arrives as a note. The base values are
resolved once per run (`AIConversation::findStateDeliveryBaseValues()`, memoized on the run).

The tail-only values (the unconfirmed-zone note, the situation note) are null in a record
(`AgentConversationState::buildEpochRecord()`), so the epoch's first call delivers them as notes. A call that renders
no situation line treats the situation as unknown, never as "every value is confirmed".

A rebuild of a recorded call whose epoch record is not in the context (an edit branch cut the epoch's first row)
fills the template's placeholders with today's values (`AIConversation::fillStateDeliveryTemplateWithCurrentValues()`)
and marks the input (`AgentModelInput::$stateEpochFilledFromCurrentValues`), so the composition view says so.

## 4. Notes

`AgentStateDelivery::buildForNewCall()` compares the current values with what the model has seen (the record with the
epoch's recorded notes applied) in `AgentStateDelta::getKindsInDeliveryOrder()`:

1. the space's context (the kind is the word `AgentApplicationWording::$agentSpaceContextStateKind`),
2. `owner-addressing`, 3. `time-zone`, 4. `standing-instructions`, 5. `plan-of-the-product`, 6. `situation`.

Each difference becomes one `AgentStateDelta` with its literal text (`AgentStateDeltaTexts`), recorded on the call's
`RequestSnapshot::$stateDeltas` and re-rendered byte for byte at the same position by every later call of the epoch.
A note is a wire item (one user entry per position, `AgentAppendedHistoryInsertion::$userTexts`), never a row: the
loop's checks and the compaction summarizer never see it. A note found by a call whose response was discarded is found
again by the next call.

Which note counts (the `{%STATE_NOTES_RULE%}` part of the template tells the model):

- the space's context note names every field changed since the RECORD, so the newest note of that kind replaces the
  earlier ones; the same holds for every whole-value kind (addressing, zone, standing instructions, situation);
- a `plan-of-the-product` note replaces only the blocks it shows.

Field-by-field change lines need the host's labels: `AgentSpaceContextFields` knows no field; a host declares
`FIELDS_IN_ORDER` and `readFieldsOfText()` in its same-path subclass. Without one the note carries the whole new text.

## 5. What still rides every call

The tail keeps what must be current at every call: the compact task plan with its owner-turn counter, the plan
directive, the direct-widget line, the loaded-skills hint, the answer contract, the clock, the answer-step line and the
reply-language cue. Under `onceWhenChanged` the situation and the unconfirmed-zone tail entries are not rendered; they
are notes.

## 6. Host contract

- The space's context text (`AgentSpacesService`, the `{%AGENT_SPACE_CONTEXT%}` value) must be deterministic and is
  read once per run; a mid-run change reaches the model at the next run start.
- Keep `{%AGENT_SPACE_CONTEXT%}`, `{%OWNER_ADDRESSING%}` and `{%STATE_NOTES_RULE%}` in a replaced `Agentic.md`.
- The model reads background values as of the epoch start plus the notes: a tool remains the source of current data,
  and an action that depends on a write in the same turn uses that write's result.

## 7. Adding a kind (maintainer)

1. A `KIND_*` constant on `AgentStateDelta` and its place in `getKindsInDeliveryOrder()`.
2. Read and write the value in `AgentConversationState` (`readValueOfKind()`, `writeValueOfKind()`, the snapshot form).
3. The note text in `AgentStateDeltaTexts`, ending in `BACKGROUND_SUFFIX` when it asks for no reply.
4. Gather the current value in `AIConversation::buildCurrentConversationState()`; drop its per-call render from the
   tail or the system content when a state delivery is active.
5. Say in the template's state-notes rule whether the newest note of the kind replaces the earlier ones.
