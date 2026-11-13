# Tool-call efficiency

**Reader:** host developer and package maintainer

The mechanisms that keep the model from spending calls on work it already did. Each is structural (the offer, the
dispatch) first; prose hints are the second line. What a tool declares to take part (`#[MCPToolEffect]`, result size,
`toLlmMarkdown()`) belongs to the tools skill.

## 1. Re-loading a skill

- **Under `systemPromptSwap`** the `load_skills` enum leaves out the loaded skills
  (`LoadSkillsTool::forCatalog()` with `OfferedSkillCatalog::$alreadyLoadedSkillNames`); the tool disappears when
  nothing is left to load (`LoadSkillsTool::hasLoadableSkills()`).
- **Under `conversationAppend`** the enum and the catalog stay the whole reachable catalog for the epoch
  (`OfferedSkillCatalog::buildReachableFor()`, nothing marked loaded) so the tools array never changes. The model is
  told instead: the LOADED_SKILLS header lists "Currently loaded" and their tools, and on a turn's first call the tail
  entry `[SIGNAL:skills] Loaded right now: …` names them (`AgentModelInput::loadedSkillsTailHint()`). Asking for a
  sub-skill without its root loads the root with it.

## 2. Repeating a read

`AIConversationToolCallHistory::getReusablePriorReadCall()` answers a repeated READ from the earlier result instead of
running it:

- the tool is a reusable read: `#[MCPToolEffect]` read-only and `resultIsReusedWithinTurn` true
  (`ToolCall::isReusableRead()`, `MCPToolEffectCatalog::isReusableReadToolName()`); the name never decides;
- the same normalized key: name plus arguments without `outputFormat` and `noCache`, keys sorted
  (`ToolCall::getNormalizedIdentityKey()`);
- completed in the CURRENT owner turn — the next owner message reads fresh, because data moves outside the
  conversation too;
- no completed call since that is not itself a reusable read — a write, or a read not declared reusable (exempt: the
  plan tools, `load_skills`, `delegate`, the owner report, `AIConversationMessages::NON_MUTATING_WORK_TOOL_NAMES` and
  tools that declare they keep earlier reads valid; a write refused as a duplicate does not count either), and no change signal since (an owner edit
  in a widget, a settled delegation, a connected channel);
- the earlier result is still on the wire: not stubbed by the retention boundary or the budget, its pictures still
  riding. Otherwise the read runs again.

The repeat gets the earlier result again with a notice, so the model has the answer and keeps every tool.

## 3. The repeat guard

`AgentRepeatedToolCallCheck` counts byte-identical calls of one signature per turn
(`AIConversationToolCallHistory::countCallsWithSignature()`): at `MAX_REPEATED_TOOL_CALLS` (3) the call does not run
and the model gets a corrective, one more aborts the turn. It is per turn (an owner who asks again next turn runs it
again) and distinct from the read reuse: the reuse answers a repeat, the guard stops a loop. It also reports a read
that already failed twice and a write already persisted.

## 4. Optional "already fetched" hint

`ContextRetentionSettings::$hintRecentToolCallsToDiscourageRepeats` (default off) adds a tail entry listing the reads
completed in the conversation since the last write. It is conversation-wide, unlike the turn-scoped reuse, and rides
the tail so it never touches the cached prefix. Leave it off unless a measurement shows varied-argument
over-exploration the reuse cannot catch.

## 5. Shared documentation once, not per parameter

When a loaded skill's package exposes query options or shared request parameters, their grammar is one system segment
(`QUERY_OPTIONS_SYNTAX`, `SHARED_PARAMS` with `AgentModelInput::OPTIONAL_PARAMETERS_RULE`) instead of being repeated
in every parameter description. The rule tells the model to pass only the parameters the task needs.

## 6. Answering what was done

`[SIGNAL:answer-content]` (`AgentModelInput::completedWritesAnswerContractHint()`) names the writes and picture reads
the turn completed, so the final answer reports them instead of acknowledging a harness note. It names what the answer
contains, never that it is due.

## 7. Stubs that would be longer than the result

A result shorter than `ToolCall::MIN_RESULT_CHARS_TO_STUB` (256 characters) is never stubbed: a stub would cost more
than it saves and changes a row the cache already holds.

## Symptoms

| Symptom | Check |
|---|---|
| `load_skills` for a loaded skill | the strategy (append-only keeps the enum whole); is the `[SIGNAL:skills]` entry present on the turn's first call |
| The same read twice in one turn, both executed | the tool's `#[MCPToolEffect]`; a write or change signal in between; the first result stubbed |
| A read reused across turns | it is not: the reuse is turn-scoped |
| A turn aborted after identical calls | the repeat guard; the tool's error text does not tell the model what to change |
