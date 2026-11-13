# Writing and dictating a widget from a tool, reading the answer

**Reader:** host developer

Contents: three ways a widget reaches the chat · the one writer and its delivery · writing from a tool · writing as the platform · a widget for a capability the agent space has not unlocked · dictating a block · what a written row is · the shown-widget view · reading an answer · the generic rule · an answerable class · form widgets · applying an edit at turn open · traps

## Three ways a widget reaches the chat

| Way | Who writes the block | When to use |
|---|---|---|
| The model writes it | the model, from a skill body, a tool description or the kit it was taught | the presentation needs the model's judgement |
| A tool or the platform writes it | the one writer, as its own row | a fact the code knows for certain: "the run started", "here are the matches to confirm" |
| A tool result dictates it | the model copies the block the result carries | only where no row can be written (no chat, a sub-agent, the phone line) |

Writing beats dictating: a model copies a dictated block unreliably, and it cannot copy a block it never saw. Build the
block from the widget's class in every case (`buildWidgetBlock()`), never from an array or a string.

## The one writer and its delivery

`DDD\Domain\AI\Services\Conversations\AIConversationMessagesService` holds the only writers of widget rows. The writer decides where the widget goes; the
caller reads the answer, a `ConversationWidgetDelivery`:

| `ConversationWidgetDelivery::$type` | When | What the caller does |
|---|---|---|
| `TYPE_WRITTEN` | a row was written (`$hostMessage` is it) | tells the model in one line that the widget is shown |
| `TYPE_NO_CHAT` | an eval-harness call, no conversation (the HTTP MCP mount), a blank block | returns the dictation note |
| `TYPE_DELEGATION_CHILD` | the call runs in a sub-agent: nobody reads a child's transcript, nothing is copied to the root | returns the dictation note (the child puts the block into its result) |
| `TYPE_PHONE_LINE` | the owner is on a phone call: nothing can be shown or clicked | returns the dictation note with a spoken instruction |

| Writer | Origin in the block | For |
|---|---|---|
| `appendWidget()` | `tool:<toolName>` | a tool call, with its `AgentToolCallProvenance` |
| `appendToolText()` | none (prose row) | a plain notice line of a tool |
| `appendPlatformWidget()` | `platform:<reason>` | the platform on its own (a purpose's widget at turn open); the reason is a constant of the writing domain |
| `appendMetaToolWidget()` | `tool:<metaToolName>` | the widget of a meta tool the platform renders at the turn's end (the `askOwner` pills) |

Each throws `AgentRunClaimLostException` (the run claim was lost) or `MessageIndexLockUnavailableException`; nothing is
written then. Let both through.

## Writing from a tool

The tool method receives its call context (`AgentToolCallProvenance`) as a parameter the server fills; how a tool
declares it and what it returns to the model is `ddd-module-agents-tools-specialist`. The widget side:

```php
use App\Domain\Notes\Entities\AI\Conversations\Widgets\NoteConfirmConversationWidget;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;

// inside the tool method; $callProvenance is its AgentToolCallProvenance parameter
$noteConfirmWidget = new NoteConfirmConversationWidget();
$noteConfirmWidget->widgetSelfHydrationKey = $callProvenance->agentSpace?->getWidgetSelfHydrationKey();
$noteConfirmWidget->noteId = (string)$note->id;
$noteConfirmWidget->label = $note->text;
/** @var AIConversationMessagesService $aIConversationMessagesService */
$aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
$conversationWidgetDelivery = $aIConversationMessagesService->appendWidget($callProvenance, $noteConfirmWidget);
$dictationNote = $noteConfirmWidget->buildDictationNote($conversationWidgetDelivery, 'Read the note aloud and ask whether to keep it.');
// null = written: tell the model "the widget is shown — write one line, do not repeat the note"
```

`AgentSpace::getWidgetSelfHydrationKey()` is the key a block of that space carries (default: the space id; a host whose
widgets read by its own entity id overrides it).

## Writing as the platform

A purpose that wants a widget at the start of a turn returns an `AIConversationPurposeTurnOpenWidget` from
`AIConversationPurpose::buildWidgetAtTurnOpen()`: the widget, the platform reason and the signal text. The turn-signal
service writes it through `appendPlatformWidget()` and, only when it was written, appends that text as a signal of the
source `PLATFORM_WIDGET_EMITTED`, which owes no work. Purposes are `ddd-module-agents-skills-and-purposes-specialist`.

## A widget for a capability the agent space has not unlocked

A tool that is locked by a flag is not offered and a skill that is locked never loads, so neither can teach a widget
whose trigger is the missing capability (an upgrade offer). Its home is the plan block: the host's
`AIConversationEntitlementsService::buildProductAwareness()` renders the text of the `PRODUCT_CAPABILITIES` segment
(`AgentProductAwareness::$productCapabilities`, empty by default), and the widget's block and rule go inside the part
of that text the host renders only when an upgrade exists — so the widget is taught exactly when it can be used. A
capability locked by a right of this user (the `RESTRICTED_CAPABILITIES` segment) is never offered as an upgrade
widget: the account's administrator grants a right, nobody buys it. Entitlements and the segments are
`ddd-module-agents-specialist` (override points) and `ddd-module-agents-context-specialist`.

## Dictating a block

`ConversationWidget::buildDictationNote()` is the one note every caller returns when the delivery wrote no row: on the
phone line it carries the caller's spoken instruction, in a sub-agent and without a chat it carries the block from
`buildWidgetBlock()` with the order "one short prose line, then the block". For a fact without a widget,
`ConversationWidgetDelivery::buildNoteForFactNotWritten()` is the twin.

A tool whose result dictates a block without a condition declares it as a typed fact on its response
(`MCPToolResponseDictatedWidgetTypeProvider`); the contract is `ddd-module-agents-tools-specialist`, the nudge the loop
sends when the block is missing is `ddd-module-agents-loop-specialist`.

## What a written row is

`MESSAGE_TOOL_MESSAGE`, role assistant, author `AUTHOR_TOOL`, `signalSource` = the writer label (the tool name, or
`platform:<reason>`). It is visible to the owner and live (a realtime tick follows the commit, so its widget is just
delivered), invisible to the model (never on the model's wire, so the model cannot echo it and the call/result pairs
stay adjacent), never owed work, written under the run claim at the next free index. `$from` bindings of a
`ComposedView` block are resolved right before the row is written. Because the model does not see the row, the tool's
result must tell it the widget is there.

## The shown-widget view

| Entry | Covers |
|---|---|
| `AIConversation::findShownWidgets()` | every widget of the conversation independent of the loaded message window, up to the newest 5,000 widget and reaction rows (`AIConversationMessagesService::CONTEXT_MESSAGE_WINDOW` × `MAX_CONTEXT_WINDOWS`), read through `AIConversationMessagesService::findWidgetAndReactionRows()` — a widget and its answer behind the compaction edge included |
| `AIConversationMessages::buildShownWidgets()` | the loaded rows only — for gates that run mid-turn |

Both return `ShownConversationWidgets`: one `ShownConversationWidget` per VALID block of an assistant row (model text,
tool or platform row), with the owner's reaction rows after it. A reaction (`ConversationWidgetReaction`) is the owner's
TEXT row or an owner-authored SIGNAL row; the loop's replay copy of a click is none.

| Question | Method |
|---|---|
| the newest widget of a type | `ShownConversationWidgets::findNewestOfType()`; before an index: `findNewestOfTypeBefore()` |
| the widgets a turn showed | `findShownAfterIndex()` with the turn's initiator index |
| still unanswered | `findUnansweredOfTypeAfterIndex()` |
| what tool rows wrote | `getWrittenTypesOfToolRowsAfter()` |
| one widget | `ShownConversationWidget::buildWidget()`, `wasAnswered()`, `isDeclined()`, `readNewestAnswer()`, `ownerWroteAfterIt()`, `getOrigin()` |

A typical reader:

```php
use App\Domain\Notes\Entities\AI\Conversations\Widgets\NoteConfirmConversationWidget;

// $conversation is a DDD\Domain\AI\Entities\Conversations\AIConversation
$noteConfirmAnswer = $conversation->findShownWidgets()
    ->findNewestOfType(NoteConfirmConversationWidget::WIDGET_TYPE)
    ?->readNewestAnswer();
```

Never add a reader to the message set that scans text for a wire name.

## Reading an answer — the generic rule

A type whose class is not answerable is read by `ConversationWidgetReaction::answersWidgetTypeByGenericRule()` and
`declinesWidgetTypeByGenericRule()`: a form submit answers its own form widget only; a `FRONTEND_WIDGET_CLICK` signal
that names the type answers it, unless it carries the word of `ConversationWidgetReaction::GENERIC_DECLINE_WORD`, which
declines it; any other owner TEXT row answers it; any other owner signal does not. It cannot tell two widgets of one
type apart.

## An answerable class

When a tool, a gate or a done criterion needs WHAT the owner chose, the class implements `DDD\Domain\AI\Entities\Conversations\Widgets\AnswerableConversationWidget`:

| Method | Rule |
|---|---|
| `isReactionToThisWidget()` | the reaction names the type AND the widget's identifying value (a row id, a quoted address) when it has one |
| `readAnswer()` | returns a subclass of `ConversationWidgetAnswer` (narrow the return type to it), or null for a decline or an unrelated row |
| `isDeclined()` | the reaction declines this widget |

`ShownConversationWidget::readNewestAnswer()` walks the reactions newest first and returns the first answer;
`wasAnswered()` and `isDeclined()` prefer this reader over the generic rule. The package's examples:
`OwnerQuestionConversationWidget` (answer `OwnerQuestionPick`: the picked labels, matched case-insensitively against the
options; the host's suffix of another agent space is stripped only when it names this widget's own space key, which then
also sets `OwnerQuestionPick::$foreignWidgetSelfHydrationKey`) and `ComposedViewConversationWidget` (answer
`ComposedViewFormSubmission`). Every owner text after the pills reacts to them, so `readNewestAnswer()` returns the pick
of the NEWEST owner text; the pick of the first one is `OwnerQuestionConversationWidget::readPickFromFirstOwnerText()`,
a separate method a caller asks itself. The reaction sentences are a stored contract, pinned by a contract fixture — see the
interaction-contracts reference. Start from the templates of this skill.

A plan gate that must know whether the NEWEST widget of a type was answered calls the static
`ConversationWidget::readOwnerAnswerOnTheNewestWidgetOfThisType()` (true answered, false declined, null not yet); the
default applies the generic rule, a class whose question can also be answered by a later tool call overrides it.

## Form widgets

A form whose submit arrives as a row of its own (a signal of a host source, or an owner text the form composed)
implements `FormSubmitConversationWidget::isFormSubmitRow()`. The registry then marks such a row
(`ConversationWidgetReaction::$formWidgetTypeSubmitted`) and the row answers that form and nothing else. When the
submit is a signal of a host source that starts an owner turn (`ConversationSignalSource::startsAnOwnerTurn()`),
`AIConversationMessages::isOwnerFormSubmitInitiator()` treats it as the owner's own words at the turn start. The kit's `Form` node needs none of this:
its submit is a widget-click signal the `ComposedView` class reads.

## Applying an edit at turn open

`ConversationWidget::applyOwnerEditAtTurnOpen()` is asked of every registered class once per turn start, before the
first model call. A class whose submit is the owner's decision on a record writes the record there and returns the
signal text that tells the model what was applied (stored as `OWNER_WIDGET_EDIT_APPLIED`); null means nothing to apply.
Idempotent per turn; a lost run claim is rethrown, other failures are swallowed by the caller.

## Traps

- A tool that writes a widget and then also tells the model to "show the widget" gets it twice. Tell the model it is
  shown, in the words "write one line on what changed, do not paste what the widget carries".
- `$hostMessage` is null unless written; never write a second row yourself when the delivery says no chat.
- A widget the owner asks for again is shown again; a tool that must reuse an earlier widget instead decides that
  itself from the shown-widget view, never by removing a block from the model's text.
- `ShownConversationWidget::getOrigin()` honours `emittedBy` only on a tool-written row; inside model text the field
  reads as the model.
