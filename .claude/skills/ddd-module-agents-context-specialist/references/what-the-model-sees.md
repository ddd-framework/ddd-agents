# What the model sees on each call

**Reader:** host developer and package maintainer

Contents: 1. How the input is built · 2. The system segments, in order · 3. The history · 4. The volatile tail ·
5. The core prompts · 6. Host hooks · 7. Adding a segment or a tail entry

## 1. How the input is built

`AIConversation::buildModelInputUpToAndIncludingMessage()` builds one `AgentModelInput` per model call, for the live
loop and for every rebuild of a recorded call. It is a value object, never persisted. The phases, in order:

| Phase | What it puts in |
|---|---|
| A identity and time | delegation flag, the zone and whether it is unverified, schedule-bound flag |
| B sub-agent directive | the `AgentAIPromptNames::APP_AI_AGENT_SUB_AGENT_DIRECTIVE` prompt, delegations only |
| C directives | the purpose directive of a root, or the scheduled-run directive |
| D task-plan tail inputs | the current plan, the plan directive, the decided direct widget |
| E reply language | the resolved language code and how it was measured |
| F history | the rows up to the anchor, after the compaction cover (section 3) |
| G presence and variants | `OwnerPresence`, the resolved variants with the transient `surface` dimension |
| H security filter, catalog | the owner rows the injection check blocked; the skill catalog the call offers |
| I creation snapshots | episodic history, day context, local situation, as stored when the conversation was created |
| J memory directives | `AIConversation::findMemoryDirectivesBlock()` |
| K product awareness | entitlement axes, the product blocks |

Then, under `stateChangeDelivery = onceWhenChanged`, the state epoch and its record replace the volatile blocks
([volatile-context-once-per-change.md](volatile-context-once-per-change.md)), and under `conversationAppend` the prefix epoch decides which skills the system
content renders ([prompt-cache-and-stable-prefix.md](prompt-cache-and-stable-prefix.md)). The skills' instruction text is resolved only at compose time.

The tools array of the call is built beside it (`AgentOfferedTools::buildFor()`: the meta tools in a fixed order, the
loaded skills' resident schemas, `load_tool` last); the tools skill owns the schemas.

## 2. The system segments, in order

`AgentModelInput::buildSystemSegments()` returns labelled `AgentContextSegment` slices whose concatenation is
`composeSystemContent()` byte for byte. Each segment owns its leading `\n\n`.

| Segment (`AgentContextSegmentType`) | When | Stable for |
|---|---|---|
| `BASE_PROMPT` | always: the cached base prompt, its `[surface=screen]` / `[surface=phone]` sections rendered for this call, `{%COMPOSED_VIEW_KIT%}` filled when `teachesComposedViewKit()` | the agent space (the surface changes it) |
| `SUB_AGENT_DIRECTIVE` | a delegation | the conversation |
| `PURPOSE_DIRECTIVE` | a purposed root; conditional sections rendered for the variants | the conversation |
| `SCHEDULED_RUN_DIRECTIVE` | a scheduled run, never its children | the conversation |
| `TIME_RULE` | a zone is known: "Times are the … local time: <zone>." | the conversation (a zone change is a note under `onceWhenChanged`) |
| `OWNER_PRESENCE` | a root; a delegation child only as `PRESENT_ON_THE_PHONE` while its root's owner is on the phone line: "Who you are talking to" | the conversation, except at a call's start and end |
| `MEMORY_DIRECTIVES` | the host returns standing directives | the account and space |
| `EPISODIC_HISTORY` | the conversation stored one at creation; roots only | the conversation |
| `DAY_CONTEXT`, `LOCAL_SITUATION` | the host composed them at creation; roots only | the conversation |
| `PRODUCT_CAPABILITIES`, `AI_BUDGET_AWARENESS`, `RESTRICTED_CAPABILITIES` | the entitlement services render something | the account |
| `SKILL_CATALOG` | an account exists: the skills the call may load (`LoadSkillsTool::renderCatalogBlock()`) | the conversation under the default `hierarchy` discovery mode (top level only, both strategies); the loaded set only in the sub-skill discovery measurement arms (`SubSkillToolDiscovery`) |
| `LOADED_SKILLS` | a skill is loaded: header, "Currently loaded: …", the situation line when a skill is cut by variants, the instruction blocks filtered by entitlement | the loaded set (the prefix epoch under append-only) |
| `QUERY_OPTIONS_SYNTAX`, `SHARED_PARAMS` | a loaded skill's package exposes them; emitted once instead of on every parameter | the loaded set |
| `COMPACTION_SUMMARY` | a compaction cover exists: the preserved facts and identifiers, then the node gists | the cover |

`AgentModelInput::estimateFixedOverheadTokens()` sums every segment except the compaction summary plus the offered
tool schemas, chars / 4; the compaction budget reads the newest stamped value.

## 3. The history

The rows of THIS conversation only (a delegation is its own conversation), sorted by index and id, cut at the anchor,
starting after the compaction cover's edge. How a row renders:

- an owner text row carries the bracket `[time | via …]` (`AIConversationMessage::buildWireBracket()`), the time in the
  zone in effect at that row; signal rows carry none;
- a tool result older than the retention boundary, or one the token budget could not fit, is replaced by a stub
  (`ToolResultRetention`, [context-reduction.md](context-reduction.md)); a result shorter than `ToolCall::MIN_RESULT_CHARS_TO_STUB` is never
  stubbed;
- pictures ride inside their tool result (see "Tool pictures" below);
- an owner row the injection check blocked renders as `AIConversationMessage::SECURITY_FILTER_NOTICE`;
- under append-only loading or `onceWhenChanged`, recorded appended items and state notes are placed at the
  positions the calls that sent them recorded; they are wire items, never rows.

**Tool pictures.** `AIConversationModelCallsService::resolveToolCallImages()` runs where the wire is composed, the one
point where both the resolved model and the pictures are known. Candidates are the completed tool results in the
context that carried pictures (`ToolCall::$imageReferences`) and still ride in full; a stubbed result carries none.
The bytes come from what the current worker invocation captured (`AIConversation::$transientToolCallImages`), or, for
a durable picture, are re-resolved on every call through the loaded package that implements `ToolCallImageStore`. An
inline picture (a browser viewport) is gone once its invocation ended. The cap counts pictures, not results
(`ContextRetentionSettings::getMaxToolCallImagesOnWire()`, default 2, a purpose's `contextRetention:` map can raise
it): newest results first and whole, the newest result always rides, truncated to the cap
(`AgentModelInput::selectToolCallImagesForWire()`). A candidate that does not ride is listed in
`AgentModelInput::$droppedImageToolCallIds` and its result gets a "no longer on the wire" marker; on a model without
vision no bytes ride (`AgentModelInput::$blindCapturedToolCallIds`) and every candidate gets the downgrade note. The
read reuse counts the pictures that still ride ([tool-call-efficiency.md](tool-call-efficiency.md)).

## 4. The volatile tail

`AgentModelInput::buildTailEntries()` is the one list the egress and the admin composition read. Every entry is a
user-role wire entry after the whole history, never stored. Order:

1. recent reads hint (`ALREADY_FETCHED_READS`), only with `ContextRetentionSettings::$hintRecentToolCallsToDiscourageRepeats`;
2. the compact task plan with the "Owner turns so far" counter;
3. the plan directive of the decision layer;
4. the decided direct widget (`[SIGNAL:widget]`);
5. `[SIGNAL:skills]` on the turn's first call while skills are loaded;
6. `[SIGNAL:answer-content]` once the turn completed a write or a picture read;
7. the image fallback entry (only for a provider that refuses pictures in tool results);
8. `[SIGNAL:situation]` (`everyCall` only);
9. the host's local-situation hint (`AgentModelInput::getLocalSituationTailHint()`, null by default);
10. `[SIGNAL:time-zone]` for an unverified zone (`everyCall` only);
11. `[SIGNAL:clock]` with the one instant captured for this call;
12. `[SIGNAL:answer-step]` on a step whose tool choice is `none`;
13. the reply-language cue `[SIGNAL:reply-language]`, always last.

## 5. The core prompts

Prompt names live on `AgentAIPromptNames`; files under `config/app/AI/Prompts/AI/` of the package. A host file at the
same path replaces the package's whole.

| Constant | File | Read by |
|---|---|---|
| `APP_AI_AGENT_AGENTIC` | `Agent/Agentic.md` | the agent's base prompt |
| `APP_AI_AGENT_CHARACTER` | `Agent/Character.md` | `{%AGENT_CHARACTER%}` inside the base prompt |
| `APP_AI_AGENT_GENERAL` | `Agent/General.md` | a specialist conversation without tools |
| `APP_AI_AGENT_SUB_AGENT_DIRECTIVE` | `Agent/SubAgentDirective.md` | segment `SUB_AGENT_DIRECTIVE` |
| `APP_AI_CONVERSATION_COMPACTION` | `Conversation/Compaction.md` | the compaction summarizer |
| `APP_AI_CONVERSATION_SUMMARY` | `Conversation/Summary.md` | the display summary of a conversation |
| `APP_AI_CONVERSATION_USER_LANGUAGE_DETECTION` | `Conversation/UserLanguageDetection.md` | `ArgusUserMessageLanguageDetection` |
| `APP_AI_SEARCH_RERANK` | `Search/Rerank.md` | `ArgusSearchRerank` |
| `APP_AI_SYSTEM_PROMPT_PUBLIC_FACING` | `SystemPrompts/PublicFacing.md` | public-facing generation |

The decision and guard prompts belong to the decisions-and-guards skill.

Placeholders of the package's `Agentic.md` (ten): `{%AGENT_NAME%}`, `{%PRODUCT_NAME%}`,
`{%PRODUCT_OPERATOR_SECTION%}`, `{%OWNER_ADDRESSING%}`, `{%AGENT_CHARACTER%}`, `{%AGENT_SPACE_CONTEXT%}`,
`{%AGENT_SPACE_ID%}`, `{%TASK_PLAN_TRIGGER%}`, `{%STATE_NOTES_RULE%}`, `{%COMPOSED_VIEW_KIT%}`. The parameters
`USER_LANGUAGE` and `USER_LANGUAGE_CODE` are set as well (`AgentSpacesService::applyAgentSpacePromptParameters()`) and
may be used by a host's own `Agentic.md`; the package file does not use them. None of them is a moment: the base prompt carries no clock. A host prompt keeps
`{%AGENT_SPACE_CONTEXT%}` and `{%OWNER_ADDRESSING%}` (filled per state epoch) and the `[surface=…]` section markers.

## 6. Host hooks

| Need | Hook |
|---|---|
| Standing directives | `AgentMemoriesService::findStandingDirectivesForAgentSpace()` ([memories-and-standing-directives.md](memories-and-standing-directives.md)) |
| Day context, local situation | `AgentSituationalContextService::composeDayContextTextForNewConversation()`, `composeLocalSituationText()` |
| How their segments are introduced; a part repeated at the tail | a same-path subclass of `AgentModelInput`: `getDayContextSegmentLead()`, `getLocalSituationSegmentLead()`, `getLocalSituationTailHint()` |
| The words for the space and the owner in module sentences | `AgentApplicationWording` (`config/app/AI/agentApplicationWording.php`) |
| The text the space contributes, its zone, the cache routing key | `AgentSpacesService` same-path subclass (entry skill) |

The day context and the local situation are snapshots stored on `AgentWorkingState` at creation: a later text never
changes a running conversation's prefix.

## 7. Adding a segment or a tail entry (maintainer)

1. Decide the stability. Stable for the conversation or wider: a system segment, placed after everything less
   stable and before everything more stable. Changing within a conversation: a tail entry, or a state kind delivered
   once ([volatile-context-once-per-change.md](volatile-context-once-per-change.md)).
2. Add the `AgentContextSegmentType` case; the segment owns its leading separator, so the byte-for-byte equality of
   `composeSystemContent()` and the joined segments holds.
3. Resolve the value in a phase of `buildModelInputUpToAndIncludingMessage()`, not inside `AgentModelInput`: the value
   object is built without a container in tests and rebuilds.
4. A rebuild must show what was sent: read the value from a stored row or snapshot, never from "now".
