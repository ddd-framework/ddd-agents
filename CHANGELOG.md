# Changelog

## 0.1.0-alpha.19

- Stall classification: text of a step the loop sent with tool choice `none` (the answer step after a refused repeat,
  an owner-gate refusal, a result that ends the purpose, the step before the budget closes) is no longer judged as
  "text after a skill load" and is delivered instead of nudged and discarded; the checks of a final text still run on
  it. `AgentStepToolChoice::letsTheModelCallTools()` / `offersWorkToolsToCall()`; trace
  `turn.stall.textOfStepWithoutToolsNotJudged`. Steps sent with tools are unchanged.

## 0.1.0-alpha.18

- The module ships its plain console commands (`DDD\Symfony\Commands\Agents\`, 20 with the mark command) and the
  chat line editor: schedule fire, compact-finalized, the two event purges, maintenance (snapshot strip, capability
  rewrite, embedding repair and backfill, environment backfill), diagnosis (diagnose, validate-conversation,
  cache-diff, activity-lines, tool-response-stats, skill-token-map, context-eviction-sim, compact, search-inspect) and
  the hidden child-process command `app:ai-agent:execute-tool-call`. A command runs on a schedule only from the cron
  line the host registers (`templates/crons.sh`).
- **Behaviour change:** `agentToolCallChildProcess.commandName` defaults to the module's
  `app:ai-agent:execute-tool-call`; `commandName: null` no longer disables forking — an application that declares a
  `consoleScript` now forks with the module's command; unset `consoleScript` to stay inline.
- The schedules service answers which skip an occurrence was (`isSkipReasonOf…()`), and every skip word is produced
  through `static::`.
- Cron rows: a host that registered the former template rows (`agent-schedules-fire`,
  `agent-conversations-finalize-idle`, `agent-events-purge`) keeps them until it deactivates them; `crons.sh` keeps
  `agent-schedules-fire` (an upsert switches its command) and names the split finalize and purge rows anew.
- Developer skills: the command list, the host duties, the cron list and the child-process contract follow the
  shipped commands; the templates for firing schedules, reducing finalized conversations and purging events are
  removed (the package commands replace them).
- Test kernel: the cache key is a fingerprint of path, time and size of every watched file.

## 0.1.0-alpha.17

- Package prompt defaults: the owner's formal or informal form of address is one rule in `Agentic.md` and
  `Character.md` (recorded with a tool only when the conversation offers one); the recall passage names no
  application skill.
- `AIConversations::getSentenceOnReadingASessionInFull()`: the default says which tool reads a session and what to pass.

## 0.1.0-alpha.16

- Task plan tick rules: an item whose read or call returned nothing usable is not done, it is blocked.
- Checked-stop bounce: when the owner asked to stop after a part and check back, the answer reports that part and ends
  with the one question; the later items stay open.
- Package prompt defaults: an answer without a widget withholds nothing; no promise to remember a personal statement;
  a limit on the text is counted before it is claimed (`Agentic.md`); the example question in "Drive" is described,
  not quoted (`Character.md`).

## 0.1.0-alpha.15

- A spec of the frontend writes its deeply nested JSON text directly: `JSON.stringify` exhausted the call stack on
  the CI runner and stopped the npm release of 0.1.0-alpha.14.

## 0.1.0-alpha.14

Composer only: the npm release of this version stopped at the specs (see 0.1.0-alpha.15).

- First pre-release of the frontend `@mgamadeus/ddd-agents` (npm, dist-tag `next`) from `web/`, released in lockstep
  with the Composer package: the agent chat, the widget renderer, the composed-view kit, the conversation client and
  the image render. Built with Vite, declarations from `tsc`; the package's own specs run in the package folder.
- Release workflow: a tag `vX.Y.Z` publishes `web/` on npm by trusted publishing with provenance; CI builds and tests
  `web/` on every push.

## 0.1.0-alpha.13

- **Host work required.** The code no longer names tools of an application. Five method attributes replace the name
  lists: `MCPToolReceivesCurrentConversationId`, `MCPToolWritesAgentMemoryOnly`,
  `MCPToolResultIsNeverStubbedByTheBudget`, `MCPToolShowsNoActivityLine`, `MCPToolSendsMessageToOwner`. A host
  declares them on its tools: without the declaration the conversation id is not filled in, a memory write neither
  runs inline beside forked calls nor refreshes the standing directives within a run, a research read is stubbed by
  the budget, the memory tool shows an activity line, and a send tool stays callable inside a scheduled run.
- Removed: `AIConversation::BUDGET_EXEMPT_TOOL_RESULT_NAMES`,
  `AgentToolCallForkJoinService::INLINE_WRITES_OF_CONVERSATION_STATE_ONLY`, the `blockedToolNames` line of
  `Purposes/Schedule/Run.md`.
- `AIConversationPurpose::getToolNamesBlockedByDeclaration()`: a purpose class blocks tools by what they declare. A
  purpose that inherits `Schedule.Run` keeps the block of send tools only when its class extends `ScheduleRunPurpose`.
- The prompt files `Agentic.md` and `Character.md`, the closing line of the earlier-sessions text
  (`AIConversations::getSentenceOnReadingASessionInFull()`, overridable) and the two catalog texts of the
  `ConversationSearchResults` widget name no tool of an optional skill. A host that binds such tools states them in its
  set class and in a subclass of the widget.
- The header of the skill catalogue says that the list names loaded skills too and that a loaded skill is not loaded
  again.

## 0.1.0-alpha.12

- A `load_skills` call that names no skill is answered with the loaded skills and their callable tools by name when
  both exist.
- The first model step of a turn whose repeated `load_skills` call the repeat guard answers, while tools of loaded
  skills are callable, is answered with those tools and no longer forces the tool-less step; the next repeated
  `load_skills` of the turn gets the generic corrective and the tool-less step as before.
- The note before the forced `send_owner_report` step asks for the outcome and names NOTHING_TO_REPORT first.

## 0.1.0-alpha.11

- Console commands: the package ships its first command, `app:ai-conversation:mark-finalized`, and the groundwork
  every later command builds on: the abstract `AgentConsoleCommand` (default account, whole-run rights lift, budget
  bypass by `--ignore-budget`, run-level usage envelope, space by `--agentSpaceId` or the application's own options;
  the tear-down runs each step once, also after a failure), `AgentConsoleProgressReporter`, `AgentEvalArmOptionsTrait`
  (every arm option needs a value) and `ContextRetentionSettingsOptionsTrait`. An application replaces a command with
  a subclass that repeats `#[AsCommand]` with the same name.
- Value objects `ContextRetentionSettingsOverride` and `CompactionSettingsOverride` (`applyTo()` returns a merged copy;
  a value that is no whole number, below the knob's minimum or too large is refused with
  `SettingsOverrideValueRefusedException`), `AgentEvalArms`, the interface `AgentRecordedRunSessionInterface`.
- `AgentSpacesService::getConsoleScopeOptions()` (an `AgentSpaceConsoleScopeOptions` set, empty by default) and
  `findAgentSpaceForConsoleScopeOptionValues()` (null by default): override points for naming a space on the console.
- `AgentAIUsageScope`: `ENVELOPE_AI_CONVERSATION_SEARCH`, `ENVELOPE_AI_AGENT_EVALUATION`, `AI_AGENT_SIMULATE_OWNER`,
  `AI_AGENT_JUDGE_EVALUATION`.
- Design-decision references of the context, autonomy and decisions-and-guards skills: six reasons now argue from the
  mechanism, and where a measurement decided they name what a host measures itself.

## 0.1.0-alpha.10

- The tool-less step after a refused identical write is forced only when the loop already refused that call once in
  the turn. Before, a repeat of a write that ran in the same turn paused the tools on its first refusal.

## 0.1.0-alpha.9

- A `done` / `not_applicable` tick on a criterion leaf the record parks (`blockedByOwnerStatement()`) is no longer
  applied: a leaf the platform already parked keeps its park, any other leaf takes the record's park now
  (`TaskPlanItem::adoptRecordParkInsteadOfTick()`, `isParkedByThePlatform()`). Behaviour change of the gate-table row
  G8 "a blocked verdict": it now reaches RECORD instead of G28.

## 0.1.0-alpha.8

Documentation only; no behaviour change.

- Context skill: a compaction node's `coveredTokens` is the model-counted footprint of its raw rows (a roll-up sums its
  children's); the per-model default threshold names `AIModelsService::defaultEffectiveContextTokensForWindow()`.
- Loop skill: the three answers to a tick that repeats an item's status, when such a call fails, what the all-no-op
  breaker counts, and that a later same-status tick with a note replaces the item's run-time note.
- Docblocks of `AIConversationCompactionNode::$coveredTokens` and `AgentTaskPlanTickOutcome::isAllAlreadyInStatus()`
  corrected; a comment no longer cites source line numbers.

## 0.1.0-alpha.7

Documentation only; no source class changes.

- The developer skills carry the remaining generic facts about the agent core (43 skill files extended); descriptions
  and the source-ownership manifest are unchanged.

## 0.1.0-alpha.6

Documentation only; no source class changes.

- Developer skills: the entry skill `ddd-module-agents-specialist` (install, wiring, host duties, configuration,
  folder → skill table, templates for the host's console commands, middlewares, `messenger.yaml` and crons) and eight
  topic skills `ddd-module-agents-{loop,context,skills-and-purposes,tools,widgets,autonomy,decisions-and-guards,observability}-specialist`.
  `references/source-ownership.tsv` of the entry skill names the owning skill of every file under `src/` and `config/`.
- Contract tests `tests/Contract/DeveloperSkill*Test.php`: skill format, source ownership, every named identifier
  exists, every PHP template passes the syntax check.
- `README.md` and `AGENTS.md` describe the current state (config defaults, the package's own suite, the `ddd` floor
  `^3.27.0`).

## 0.1.0-alpha.5

- A `delegate` call that names `toolName` is a tool run, also when it carries a `task` text beside it (the text is
  not read). Before, such a call was refused and the model ran the tool itself in the main conversation. Only a call
  with neither shape is refused.

## 0.1.0-alpha.4

- The answer contract names what the final answer contains, not that it is due: a part of the owner's request that
  is still open is worked first.
- The plan directives that ask for a plan are no longer sent once the turn changed the plan; the plan-creating
  directive names sub-steps and variants as subtasks; the direct band asks for every call the request needs.
- The result text of an unchanged plan resend orders no work: the next open item, or the answer when the owner asked
  to be asked first (`TaskPlan::buildUnchangedResendResultText()`).
- A load call the repeat guard answered counts as no load for the stall check.
- A turn's first model call names the skills loaded right now (`[SIGNAL:skills]`, tail entry kind `LOADED_SKILLS`).

## 0.1.0-alpha.3

- The grant result of a standing mandate (time window or unlimited) asks the model to tell the customer in one
  sentence what it may now do on its own, until when, and that a word from them stops it.
- System prompt: a plan item is `done` only when its result exists; a load, create or publish item whose call could
  not deliver is `blocked`. A blocker is reported together with the alternative that is available.

## 0.1.0-alpha.2

- The answer contract owes the outcomes of the writes since the owner's newest input: their text or, newer than it,
  their own signal (a widget submit, an action confirmation). Before, a turn started by a widget submit listed the
  writes of the previous turn again, and the model repeated that turn's whole answer.
- The doc comment of `CustomVisualizationConversationWidget::$script` names the package's sandbox global
  `window.AGENTS_VISUALIZATION`.

## 0.1.0-alpha.1

First pre-release. Requires `mgamadeus/ddd` ^3.27.0, `mgamadeus/ddd-ai` ^3.2.1, `mgamadeus/ddd-argus` ^1.3.2,
`mgamadeus/ddd-common-money` ^1.1.1, `erusev/parsedown` ^1.7.

First version of the module: the agentic loop on top of ddd-ai.

- Conversations, messages, agent runs and turns, the task plan, purposes, delegation to sub-agents, schedules,
  mandates, compaction, the decision calls and the supervisor, with their repositories for MariaDB and PostgreSQL.
- The agent space (`AgentSpace`): the work context a conversation, a schedule and a mandate belong to. An
  application derives it and references its own entities from it.
- Conversation widgets and the composed view: block syntax, the widget registry, the kit of composed nodes and the
  names an application declares for its own blocks (`Widgets/Syntax/WidgetBlockFieldNames`).
- The MCP / JSON-RPC wire DTOs and the tool executor under `DDD\Presentation\Mcp\`.
- What an application declares instead of the module: its names outside a conversation
  (`config/app/AI/agentApplicationNames.php`), its wording towards the model
  (`config/app/AI/agentApplicationWording.php`), its purposes, skills, tools and widgets.
- `AgentsModule` with the namespaces the module owns (`AgentsModule::OWNED_NAMESPACES`).
