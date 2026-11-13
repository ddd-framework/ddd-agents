<?php
// placeholder {{NAMESPACE}} sample: App\Domain\Notes\Entities\AI\Conversations\Widgets
// placeholder {{WIDGET_TYPE}} sample: NoteConfirm
// placeholder {{ID_FIELD}} sample: noteId
// placeholder {{SUMMARY}} sample: One note the agent proposes to keep, with a keep and a discard button.
// placeholder {{WHEN_TO_USE}} sample: After the owner dictated a note, so the owner confirms it with one click.

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Entities\Conversations\Widgets\AnswerableConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetReaction;

/**
 * The confirm widget of one record: the owner keeps or discards it with one click. Self-hydrating: the block carries
 * the agent space key and the record id; the frontend component reads the rest itself.
 *
 * Copy to `src/Domain/<YourDomain>/Entities/AI/Conversations/Widgets/` under the root namespace `App` (the widget
 * registry scans only the application root `App` and the modules; under another root namespace the class is never
 * registered and no answer is read — see the entry skill's override points). Register
 * the frontend component under the same wire name and add the contract fixture next to it
 * (`widget-type-widget-contract.json` of this skill). The sentences below are a stored contract: never reword them.
 * `readAnswer()` and `isDeclined()` rely on the reader calling `isReactionToThisWidget()` first, as
 * `ShownConversationWidget::readNewestAnswer()` does (newest reaction first).
 */
class {{WIDGET_TYPE}}ConversationWidget extends ConversationWidget implements AnswerableConversationWidget
{
    public const string WIDGET_TYPE = '{{WIDGET_TYPE}}';

    public const bool AWAITS_OWNER_INPUT = true;

    public const string OWNER_CONTROL = 'confirm or decline';

    public const string SUMMARY = '{{SUMMARY}}';

    public const string WHEN_TO_USE = '{{WHEN_TO_USE}}';

    public const bool READS_WIDGET_SELF_HYDRATION_KEY = true;

    /** The confirm sentence the component sends as a FRONTEND_WIDGET_CLICK signal, followed by the quoted id and a dot. */
    public const string CONFIRM_SENTENCE_PREFIX = 'User confirmed on the {{WIDGET_TYPE}} widget: {{ID_FIELD}} ';

    /** The decline sentence; it carries the generic decline word. */
    public const string DECLINE_SENTENCE_PREFIX = 'User declined on the {{WIDGET_TYPE}} widget: {{ID_FIELD}} ';

    /** @var string The id of the record the widget proposes — the identifying field its reactions name. */
    public string ${{ID_FIELD}} = '';

    /** @var string|null The text the widget shows as its headline, in the owner's language. */
    public ?string $label = null;

    /** The sentence part that names THIS widget: the quoted id and the closing dot. */
    public function buildIdentifyingSentenceEnd(): string
    {
        return '"' . $this->{{ID_FIELD}} . '".';
    }

    /** A widget click that names the type and ends with this widget's id. */
    public function isReactionToThisWidget(ConversationWidgetReaction $conversationWidgetReaction): bool
    {
        return $this->{{ID_FIELD}} !== ''
            && $conversationWidgetReaction->isWidgetClickSignal()
            && $conversationWidgetReaction->namesWidgetType(static::WIDGET_TYPE)
            && str_ends_with(trim($conversationWidgetReaction->text), $this->buildIdentifyingSentenceEnd());
    }

    /** The confirm, read as the answer; null for the decline. */
    public function readAnswer(ConversationWidgetReaction $conversationWidgetReaction): ?{{WIDGET_TYPE}}Answer
    {
        if (!str_starts_with(trim($conversationWidgetReaction->text), static::CONFIRM_SENTENCE_PREFIX)) {
            return null;
        }
        $answer = new {{WIDGET_TYPE}}Answer();
        $answer->reactionIndexInConversation = $conversationWidgetReaction->indexInConversation;
        $answer->confirmedId = $this->{{ID_FIELD}};
        return $answer;
    }

    public function isDeclined(ConversationWidgetReaction $conversationWidgetReaction): bool
    {
        return str_starts_with(trim($conversationWidgetReaction->text), static::DECLINE_SENTENCE_PREFIX)
            && str_contains($conversationWidgetReaction->text, ConversationWidgetReaction::GENERIC_DECLINE_WORD);
    }

    /** The fields in block order; an optional field left null is not written. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            '{{ID_FIELD}}' => $this->{{ID_FIELD}},
            'label' => $this->label,
        ]);
    }
}
