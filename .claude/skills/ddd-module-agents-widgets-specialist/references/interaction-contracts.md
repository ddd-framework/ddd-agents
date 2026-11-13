# Interaction contracts

**Reader:** host developer

Contents: what comes back from a widget · the click signal and its endpoint · reaction sentences · the contract fixture · widgets the owner composes · what the client reports about rendering · the flags a component receives

## What comes back from a widget

| The owner… | Arrives as | Read by |
|---|---|---|
| clicks a pill | the label as the owner's own TEXT row (a host suffix appended on a widget of another agent space) | `OwnerQuestionConversationWidget::readAnswer()` |
| confirms, declines, picks, connects | a SIGNAL row of `FRONTEND_WIDGET_CLICK` (or another client source) with one sentence | the widget's `readAnswer()` / `isDeclined()`, else the generic rule |
| submits a kit form | a `FRONTEND_WIDGET_CLICK` signal, `ComposedView form "<name>" submitted:` plus one line per field | `ComposedViewConversationWidget::readAnswer()` |
| submits a form widget with its own row | a signal of a host source, or an owner text the form composed | `FormSubmitConversationWidget::isFormSubmitRow()` |
| marks something in the composer | a widget block inside the owner's own message | the skill that declares the type (below) |
| types | the owner's TEXT | the generic rule: it answers the newest widgets |

A widget never sends a canned prompt in the owner's name ("help me improve X"). It shows state and performs real
actions — an endpoint call, a signal, a submit, a canvas — and the model proposes next steps in its prose. Pills whose
labels the model wrote are the model proposing.

## The click signal and its endpoint

`AgentConversationWritesTrait::injectSignal()` serves `POST /{aiConversationId}/injectSignal` with
`AIConversationInjectSignalPostRequestDto` (`signalText`, optional `signalSource`, default `FRONTEND_WIDGET_CLICK`):

- the conversation is read under the caller's rights; a closed conversation refuses (`ConversationClosedException`);
- `AgentConversationControllerHooksTrait::assertSignalSourceIsPostedByTheClient()` refuses every source whose
  `isPostedByTheClient()` is false — a client can never post a work order, a supervisor or a loop signal;
- `AIConversationTurnSignalsService::injectSignal()` sanitizes the text, cuts it to
  `AIConversationMessage::MAX_SIGNAL_TEXT_CHARS`, stores the row, then: a source that owes no work is stored for the
  next turn and nothing else happens; a source a running turn picks up (`isPickedUpByRunningTurn()`) waits for that
  turn's next model call; otherwise the conversation is resumed.

The signal is stored as a SIGNAL row the owner does not see in the chat and the model reads as
`[SIGNAL:<source>] <text>` (signal sources reference). Sending a click as a TEXT row instead would show the owner a
message they did not write.

## Reaction sentences

The sentence a component sends is a stored contract: rows that carry it live forever, and the class reads them.

1. It names the wire name. The generic rule and `ConversationWidgetReaction::namesWidgetType()` look for it.
2. A decline carries the word of `ConversationWidgetReaction::GENERIC_DECLINE_WORD`; a confirm never does.
3. When two widgets of one type can be on screen, the sentence carries the widget's identifying field (a row id, a
   quoted address), so `isReactionToThisWidget()` can tell them apart. Of the package's widgets, a kit form is told
   apart by its `name`. The pills cannot be told apart: every owner text after them reacts to them;
   `ShownConversationWidget::readNewestAnswer()` returns the pick of the NEWEST one,
   `OwnerQuestionConversationWidget::readPickFromFirstOwnerText()` the pick of the first one.
4. Never reword a sentence once rows carry it. Extend it only by appending a field, and keep the reader accepting the
   old form (the old sentence then belongs to the newest widget of its type before the row).
5. Keep the sentence in a class constant the component's contract fixture and every model-facing text quote — never a
   copy.

## The contract fixture

Every answerable widget has a fixture `<kebab-name>-widget-contract.json` next to its frontend component, read by the
backend reader's test and the frontend composer's test alike. The package's own fixtures belong to its web half,
which is not released yet. The shape:

| Key | Holds |
|---|---|
| "wireName" | the `WIDGET_TYPE` |
| `description` | how the owner answers and which class reads it |
| "exampleBlocks" | blocks as stored: one WITH `emittedBy` when the backend writes the widget, one without |
| "exampleReactions" | each: "exampleBlock" (index), `messageType` (`TEXT` or `SIGNAL`), `signalSource` for a signal, `text`, "expectsAnswer", "expectsDecline", and an optional "expectedAnswer" object: the answer's public fields and their values (the host's test compares them) |

**The package ships no test that reads these fixtures.** A host writes one: for each type of
`ConversationWidgetClasses::getAnswerableWidgetTypes()`, require a fixture; for each example reaction, find the block
with `WidgetBlocks::fromMarkdown()` and `WidgetBlocks::findFirstBlockOfTypes()`, restore it with
`WidgetBlock::buildWidget()`, build the reaction as a plain `ConversationWidgetReaction` value object (message type,
signal source, text, a position), and assert the reader against the expectations. Also fail when two example blocks of
one type would compose the same sentence. The template folder of this skill carries a fixture to start from.

```php
namespace App\Tests\Agent\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Widgets\AnswerableConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetReaction;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use PHPUnit\Framework\TestCase;

class WidgetContractFixturesTest extends TestCase
{
    /** The host's folder of `<kebab-name>-widget-contract.json` files. */
    public const string FIXTURE_DIRECTORY = __DIR__ . '/Fixtures';

    public function testEveryAnswerableWidgetReadsItsFixture(): void
    {
        foreach (ConversationWidgetClasses::getAnswerableWidgetTypes() as $widgetType) {
            $fixtureFile = static::FIXTURE_DIRECTORY . '/' . strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $widgetType)) . '-widget-contract.json';
            self::assertFileExists($fixtureFile, $widgetType . ' has no contract fixture');
            $fixture = json_decode((string)file_get_contents($fixtureFile), true, 32, JSON_THROW_ON_ERROR);
            foreach ($fixture['exampleReactions'] as $exampleReaction) {
                $exampleBlockMarkdown = $fixture['exampleBlocks'][$exampleReaction['exampleBlock']];
                $conversationWidget = WidgetBlocks::fromMarkdown($exampleBlockMarkdown)->findFirstBlockOfTypes($widgetType)?->buildWidget();
                self::assertInstanceOf(AnswerableConversationWidget::class, $conversationWidget);
                $conversationWidgetReaction = new ConversationWidgetReaction();
                $conversationWidgetReaction->messageType = $exampleReaction['messageType'] === AIConversationMessage::MESSAGE_SIGNAL
                    ? AIConversationMessage::MESSAGE_SIGNAL
                    : AIConversationMessage::MESSAGE_TEXT;
                $conversationWidgetReaction->signalSource = $exampleReaction['signalSource'] ?? null;
                $conversationWidgetReaction->text = $exampleReaction['text'];
                $conversationWidgetReaction->indexInConversation = 1;
                $isReaction = $conversationWidget->isReactionToThisWidget($conversationWidgetReaction);
                $conversationWidgetAnswer = $isReaction ? $conversationWidget->readAnswer($conversationWidgetReaction) : null;
                self::assertSame($exampleReaction['expectsAnswer'], $conversationWidgetAnswer !== null, $exampleReaction['text']);
                self::assertSame($exampleReaction['expectsDecline'], $isReaction && $conversationWidget->isDeclined($conversationWidgetReaction));
                foreach ($exampleReaction['expectedAnswer'] ?? [] as $answerFieldName => $expectedValue) {
                    self::assertSame($expectedValue, $conversationWidgetAnswer?->{$answerFieldName});
                }
            }
        }
    }
}
```

The signal source of a click is `AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK`, as the fixture writes it.
The registry throws its build errors under `APP_ENV=test`, so the same test also fails a broken widget class.

## Widgets the owner composes

A block can travel from the owner to the agent: the composer puts it into the owner's own message (a marked element of
a page, an entity the owner asks about). `EntityMentionConversationWidget` is the package's one such type: `entityType`
and `entityId` name the entity, `label` identifies nothing.

A skill that handles such a block declares its type in the frontmatter `preloadOnWidgetTypes`; the declaring skills are
loaded for the turn whose owner row carries the block. The preload and its gates are
`ddd-module-agents-skills-and-purposes-specialist`.

## What the client reports about rendering

| Source | When the client sends it | Effect |
|---|---|---|
| `FRONTEND_WIDGET_RENDER_REPORT` | a message's blocks did not all mount (`empty`, or `failed` with a code) | owes no work: stored for the next turn, never resumes |
| `FRONTEND_WIDGET_INVALID` | the client found a block it cannot render | **owes work:** each post resumes an idle conversation — a turn of its own in which the model reads the corrective |
| `FRONTEND_NAVIGATION` | the owner opened or returned from a flow outside the chat | **owes work:** each post resumes an idle conversation — a turn (model calls, cost) for every open and return |

Only the render report owes no work (`AIConversationSignalSource::owesWork()`); neither of the other two is picked up
by a running turn, so `injectSignal()` resumes the conversation for each post. A client that posts a navigation on
every page change pays a turn per change: post only the transitions the agent must answer. The package's enum docblock
says the client sends the render report at most once per message and never for a replayed one; that is the web half's
duty, nothing on the backend enforces it.

The codes the frontend adds to the schema's finding codes are `ComposedViewKitSpec::FRONTEND_RENDER_REPORT_CODES`; the
model is taught them with the kit.

## The flags a component receives

These are the web half's (not released yet); the backend does not see them.

| Flag | True when | Use for |
|---|---|---|
| interactive | a message delivered live, or the newest reply after a reload | enabling controls |
| justDelivered | delivered live right now; never after a reload or on replay | one-shot side effects (open a canvas, create a draft) |
| openedByPage | a page mounted the widget by name, without a message | no creation side effects |

A widget that acts on mount without checking justDelivered acts again on every replay.
