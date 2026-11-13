# Changelog

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
