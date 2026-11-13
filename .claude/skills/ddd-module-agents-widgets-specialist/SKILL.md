---
name: ddd-module-agents-widgets-specialist
description: "Builds what the agent of mgamadeus/ddd-agents shows besides text and reads the owner's reaction. Covers ConversationWidget classes and WIDGET_TYPE wire names, the ConversationWidgetClasses folder registry, the widget fence block syntax (flat JSON, emittedBy, WidgetBlocks parsers), writing a widget from a tool or the platform (appendWidget, ConversationWidgetDelivery, buildDictationNote), reading the owner's answer (ShownConversationWidgets, AnswerableConversationWidget, readAnswer, contract fixtures), findWidgets, the ComposedView kit (nodes, slots, budgets, findings, $from bindings, $source data sources, entity references, Form submits), signal sources and injectSignal, realtime signals, activity lines, A2A push configs and EXTERNAL_AGENT conversations. Use when adding a widget type, a tool must show a widget, reading what the owner clicked or submitted, a widget renders nothing, adding a ComposedView node or data source, declaring a signal source, wiring realtime push, or an external agent calls the agent."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents Widgets Specialist

How the agent of `mgamadeus/ddd-agents` shows something other than text — a list, answer pills, a form, a composed
dashboard — how the owner answers on it, and how a tool writes a widget or reads that answer. This skill owns the
backend side and the wire contract between the backend and any renderer. The entry skill
(`ddd-module-agents-specialist`) installs the module; this skill starts where a host adds its first widget.

## Scope

| This skill owns | Classes |
|---|---|
| Widget classes and the registry | `ConversationWidget`, `ConversationWidgetClasses`, the package's widget classes, `ConversationWidgetOrigin`, `ConversationWidgetDelivery`, `ConversationWidgetRowChangeSignal` |
| Block syntax | `WidgetBlocks`, `WidgetBlock`, `WidgetBlockSerializer`, `WidgetBlockFieldNames`, the parsers below `Widgets/Syntax/` |
| Reading what was shown and answered | `ShownConversationWidgets`, `ShownConversationWidget`, `ConversationWidgetReaction`, `AnswerableConversationWidget`, `ConversationWidgetAnswer`, `FormSubmitConversationWidget`, `OwnerQuestionConversationWidget`, `OwnerQuestionPick` |
| The widget catalog | `ConversationWidgetCatalog`, `FoundWidgetReach`, `FindWidgetsTool` |
| The composed-view kit | everything below `Widgets/Composed/` (nodes, bindings, data sources, entity references, schema, kit spec), `ComposedViewBindingResolutionService` |
| Signal sources | `ConversationSignalSource`, `ConversationSignalSources`, `ConversationSignalSourceDefaultAnswers`, `ConversationSignalSpeaker`, `AIConversationSignalSource`, `PhoneConversationSignalSource`, `AIConversationInjectSignalPostRequestDto` |
| Realtime and activity | `AIConversationRealtimeSignalService`, `AgentActivityLinesService`, `ArgusAgentActivityLines`, `AgentActivityLine`, `AgentActivityTurnFeed`, the activity DTOs, `ActivityLines.md` |
| External agents | `AIConversationTaskPushNotificationConfig`, `AIConversationTaskPushNotificationConfigsService` |

Not here, one sentence each: the return contract of a tool that writes a widget, and how a tool receives its
`AgentToolCallProvenance`, are `ddd-module-agents-tools-specialist`; owner consent before an outward action is
`ddd-module-agents-decisions-and-guards-specialist`; the loop's stall classification and the message writes under the
run claim are `ddd-module-agents-loop-specialist`; a skill file's `providesWidgetTypes` and `preloadOnWidgetTypes` and a
purpose's widgets are `ddd-module-agents-skills-and-purposes-specialist`; who the agent talks to and the prompt
segments are `ddd-module-agents-context-specialist`; owner reports by mail are `ddd-module-agents-autonomy-specialist`.
The React components and the npm half of the package get their own skill when the web source is handed over.

## Rules that always hold

1. **One class per wire name.** Every widget type the frontend renders has exactly one `ConversationWidget` subclass
   named `<WIDGET_TYPE>ConversationWidget`, in `src/Domain/<YourDomain>/Entities/AI/Conversations/Widgets/`. The
   registry finds it by folder; a class elsewhere does not exist for the agent.
2. **Wire names are append-only.** Never rename or remove a `WIDGET_TYPE` that stored rows carry, never end one in
   `Directive`. Stored rows are never rewritten.
3. **One written form.** Blocks are written by `WidgetBlockSerializer::buildFenceBlock()` — through
   `ConversationWidget::buildWidgetBlock()` — as a fence with flat JSON next to `type`. Never assemble a block from an
   array or a string, never nest a `payload` object.
4. **The code writes what it knows.** A tool writes its widget through `AIConversationMessagesService::appendWidget()`;
   the writer decides the delivery. Dictate a block (`buildDictationNote()`) only when the delivery says no row was
   written.
5. **The class reads its own answer.** A reader asks `AIConversation::findShownWidgets()` and the widget's class; no
   reader finds a block by matching text. Reactions are matched to a type by substring
   (`ConversationWidgetReaction::namesWidgetType()`), so every reaction sentence names the wire name.
6. **Reaction sentences are a stored contract.** They name the wire name, and the widget's identifying value when two
   widgets of the type can be on screen; they are never reworded, and a contract fixture pins them.
7. **Prose first.** Everything that teaches a widget asks for one short prose line, then the block; a turn of a backtick
   block alone is an empty turn unless the widget is writable without a skill — even one that awaits the owner.
8. **A signal source declares itself.** Its turn semantics and whether a client may post it are answers of its enum
   case; `isPostedByTheClient()` is the only gate between a browser and a turn.
9. **Pings, never payloads.** The realtime signal carries ids and a status; the client reads content through the REST
   reads and their rights.
10. No closures in regular methods, no `private`, no `final`; services through the container, widgets and nodes as
    plain value objects.

## Traps

- A same-path subclass of a package widget under the host's `Domain/AI/Entities/Conversations/Widgets/` is NOT found;
  an override sits in a domain widget folder with the same short name and extends the package class.
- Registry build errors (two classes with one name, a wrong short name, a class that does not load) are logged and
  skipped in production and throw only under tests — keep a test that builds `ConversationWidgetClasses`.
- A written widget row is invisible to the model: the tool's result must say that the widget is shown, or the model
  repeats it or claims it is missing.
- `ConversationWidgetDelivery::$hostMessage` is null unless the row was written; do not write a second row yourself.
- The space key goes into `$widgetSelfHydrationKey`, never into a field of your own; the block field name is the
  host's (`WidgetBlockFieldNames`).
- Every `*ConversationWidget.php` in a widget folder is autoloaded once per process: a class that is no widget is
  skipped silently, but a file whose class does not load by PSR-4 is a build error — give answer classes another
  suffix.
- The package ships no test for contract fixtures and no command that writes the composed-view schema file: the host
  writes both.
- `$from` bindings resolve when the row is stored; a block the model typed with a "resolved" value is resolved again.
- The realtime service and the push dispatch do nothing until the host overrides them.

## Discovery needs the root namespace `App`

Every folder registry of this skill (widgets, nodes, data sources, entity-reference resolvers, signal sources) turns a
file into a class through `AgentSourceRootsService`, whose default roots are the application root `App` at
`src/` below `DDDService::getRootDir()` and the installed modules under `DDD`. A host whose classes live under another
PSR-4 root gets **no registered widget**: its blocks parse, `WidgetBlock::buildWidget()` returns null, no answer is
ever read, and its nodes, sources and resolvers do not exist. How a host under another root namespace adds its source
root, and the order in which same-path overrides (`WidgetBlockFieldNames`, the realtime service) are looked up, is
`ddd-module-agents-specialist` (override points).

## Minimal template

A read-only, self-hydrating widget for the reference kernel's notes, and the tool line that writes it:

```php
namespace App\Domain\Notes\Entities\AI\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;

/** The owner's notes, read by the component through the notes endpoint of the space. */
class NotesListConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'NotesList';

    public const string OWNER_CONTROL = 'none (read-only list)';

    public const string SUMMARY = 'The owner\'s notes as a list, newest first.';

    public const string WHEN_TO_USE = 'The owner asks what the notes say and there are more than three.';

    /** @var int|null How many notes to show; null = all. */
    public ?int $limit = null;

    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields(['limit' => $this->limit]);
    }
}
```

```php
use App\Domain\Notes\Entities\AI\Conversations\Widgets\NotesListConversationWidget;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;

// inside the tool method; $callProvenance is its AgentToolCallProvenance parameter
$notesListWidget = new NotesListConversationWidget();
$notesListWidget->widgetSelfHydrationKey = $callProvenance->agentSpace?->getWidgetSelfHydrationKey();
/** @var AIConversationMessagesService $aIConversationMessagesService */
$aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
$conversationWidgetDelivery = $aIConversationMessagesService->appendWidget($callProvenance, $notesListWidget);
$noteForTheModel = $notesListWidget->buildDictationNote($conversationWidgetDelivery, 'Read the three newest notes aloud.')
    ?? 'The notes are shown as a widget. Write one short line about them; do not list them again.';
```

The written block: a fence holding `{"type":"NotesList","agentSpaceId":42}`, with `emittedBy` naming the tool. For an
answerable widget start from [the widget class template](templates/WidgetTypeConversationWidget.php), its
[answer class](templates/WidgetTypeAnswer.php) and its [contract fixture](templates/widget-type-widget-contract.json).

## Steps — a new widget type

1. The class in the host's widget folder; decide `AWAITS_OWNER_INPUT`, `OWNER_CONTROL`, `SUMMARY` and `WHEN_TO_USE`.
2. The frontend component registered under exactly the `WIDGET_TYPE`, parsing the fields `getWidgetFields()` writes.
3. Make it reachable: a skill whose frontmatter lists it in `providesWidgetTypes` and whose body or tool shows the
   block, a tool that writes it, or `IS_WRITABLE_WITHOUT_A_SKILL` for a widget the model fills from what it has.
4. If a tool or gate needs the owner's answer: `AnswerableConversationWidget`, an answer class, the reaction sentences
   as constants, the contract fixture, and a test of the host that reads the fixture.
5. If the owner's click starts a turn of its own or has its own meaning: a signal source in the host's enum.
6. If it may sit in a `ComposedView`: `EMBEDDABLE` and `EMBEDDED_SIZE_CLASS`, then regenerate the schema file.

## Task or symptom → reference

**Host developer**

| Task or symptom | Reference |
|---|---|
| Add a widget type; choose its constants; where the class goes; override a package widget; the package's own widgets | [widget classes and wire names](references/widget-classes-and-wire-names.md) |
| The model never writes my widget; `findWidgets` does not find it | [widget classes and wire names](references/widget-classes-and-wire-names.md) |
| What a block looks like; field order; `emittedBy`; a block renders nothing; an old `…Directive` row | [block syntax](references/block-syntax.md) |
| A turn with only a widget counts as empty | [block syntax](references/block-syntax.md) |
| A tool shows a widget; the platform writes one at turn open; the phone line or a sub-agent | [writing and reading widgets](references/writing-and-reading-widgets.md) |
| Read what the owner picked, confirmed, declined or submitted; a plan gate waits for an answer | [writing and reading widgets](references/writing-and-reading-widgets.md) |
| The click sentence, the inject-signal endpoint, the contract fixture, a widget the owner composes, render reports | [interaction contracts](references/interaction-contracts.md) |
| A table, chart, KPI tiles or a form without a domain widget; a node type of my own; findings in a tree | [composed-view nodes](references/composed-view-nodes.md) |
| Which node types exist, their categories and slots | [composed-view node types](references/composed-view-node-types.md) |
| Bind a tool result (`$from`), a live data source (`$source`), open an entity from a tree | [bindings and data sources](references/bindings-and-data-sources.md) |
| A signal source of my own; which sources a client may post | [signal sources](references/signal-sources.md) |
| Push changes to the open chat; activity lines while a turn runs | [realtime and push](references/realtime-and-push.md) |
| Another software agent calls the agent; task state; webhooks | [external agents](references/external-agents.md) |
| Copy an answerable widget | [widget class template](templates/WidgetTypeConversationWidget.php), [answer template](templates/WidgetTypeAnswer.php), [fixture template](templates/widget-type-widget-contract.json) |

**Package maintainer**

| Task or symptom | Reference |
|---|---|
| Why widgets are blocks, one class per name, append-only names, the one writer, `emittedBy` | [design decisions](references/design-decisions.md) |
| A new written syntax; a reader that matches text | [block syntax](references/block-syntax.md) |
| A new core widget; the registry's build errors and override rule | [widget classes and wire names](references/widget-classes-and-wire-names.md) |
| A new core node type, budget, finding code or tone; the generated kit section | [composed-view nodes](references/composed-view-nodes.md) |
| The node inventory after a node class changed | [composed-view node types](references/composed-view-node-types.md) |
| The `$from` resolution states, snapshots and reference minting | [bindings and data sources](references/bindings-and-data-sources.md) |
| A new core signal source or question | [signal sources](references/signal-sources.md) |
| Why signals are stored apart, the kit is one type, the reader never refuses | [design decisions](references/design-decisions.md) |

## Cross-references

- `ddd-module-agents-specialist` — installation, transports (`ai_activity_lines`), override points, host duties.
- `ddd-module-agents-tools-specialist` — a tool's call context and its return contract, dictated widget types.
- `ddd-module-agents-skills-and-purposes-specialist` — `providesWidgetTypes`, `preloadOnWidgetTypes`, purposes that
  end on a widget or write one at turn open.
- `ddd-module-agents-loop-specialist` — stall classification, message writes, run claims and release.
- `ddd-module-agents-context-specialist` — owner presence, the base prompt and its cache.
- `ddd-module-agents-decisions-and-guards-specialist` — owner consent, the turn-start decision.
- `ddd-module-agents-autonomy-specialist` — owner reports and the mail that may render widgets.
- `ddd-module-agents-observability-specialist` — debugging a turn, inspecting rows and their blocks.
