<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestion;

/**
 * The single-choice pills of an owner question — the click posts the label as the owner's text (with
 * the client's suffix appended on a widget of another space). The frontend reads `options` only (2 to 5 distinct
 * labels); `descriptions`, `questionKey` and `optionMeanings` (declared on {@see OwnerQuestionConversationWidget}) are
 * the fields `askOwner` writes for the platform's read-back.
 */
class QuickRepliesConversationWidget extends OwnerQuestionConversationWidget
{
    public const string WIDGET_TYPE = 'QuickReplies';

    public const string SUMMARY = 'Short answer pills under a closed question — one click sends that option\'s text as the owner\'s message.';

    public const string WHEN_TO_USE = 'Right under a closed (yes/no/either-or) question, so the owner can answer with one click instead of typing. Each option must be a short, complete answer the owner could also type verbatim — not a label for a longer action. Skip for an open-ended question (free text is the only sensible answer) and for multi-select (`MultiChoiceConversationWidget`).';

    public const string OWNER_CONTROL = 'answer pills';

    /** @var string[] The answer labels, 2 to 5. */
    public array $options = [];

    /**
     * The pills of a single-choice owner question — the fields `askOwner`'s widget carries:
     * the question, the labels, the descriptions only when one option has one, and the catalogue key with its meanings.
     */
    public static function fromAskOwnerQuestion(AskOwnerQuestion $askOwnerQuestion): static
    {
        $quickRepliesWidget = new static();
        $quickRepliesWidget->question = $askOwnerQuestion->question;
        $quickRepliesWidget->options = $askOwnerQuestion->getOptionLabels();
        $descriptions = $askOwnerQuestion->getOptionDescriptions();
        // the rule of `AskOwnerTool::buildWidgetMarkdown()`: `array_filter` drops null, '' and '0' alike
        $quickRepliesWidget->descriptions = array_filter($descriptions) !== [] ? $descriptions : null;
        $quickRepliesWidget->questionKey = $askOwnerQuestion->questionKey;
        $quickRepliesWidget->optionMeanings = $askOwnerQuestion->questionKey !== null ? $askOwnerQuestion->optionMeanings : null;
        return $quickRepliesWidget;
    }

    /** One pick: the click posts one label. */
    public function isMultipleChoice(): bool
    {
        return false;
    }

    protected function getWrittenOptions(): array
    {
        return $this->options;
    }

    /**
     * The fields in block order (`askOwner`'s order). An optional field left null is not written — except the meanings
     * next to a catalogue key: `AskOwnerTool::buildWidgetMarkdown()` writes the pair together, a null meaning list
     * included, and so does the widget.
     */
    protected function getWidgetFields(): array
    {
        $widgetFields = static::withoutNullWidgetFields([
            'question' => $this->question,
            'options' => $this->options,
            'descriptions' => $this->descriptions,
        ]);
        if ($this->questionKey !== null) {
            $widgetFields['questionKey'] = $this->questionKey;
            $widgetFields['optionMeanings'] = $this->optionMeanings;
        }
        return $widgetFields;
    }
}
