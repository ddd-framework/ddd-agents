<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionOption;

/**
 * The multi-choice widget of an owner question — the confirm posts `confirmAction` with the picked
 * labels joined by `, ` (with the client's suffix appended on a widget of another space). `askOwner` writes
 * `question`, `options` and `confirmAction`; the model may also set the headline and the button texts. The read-back
 * fields of {@see OwnerQuestionConversationWidget} are read from a block that carries them and never written.
 */
class MultiChoiceConversationWidget extends OwnerQuestionConversationWidget
{
    public const string WIDGET_TYPE = 'MultiChoice';

    public const string OWNER_CONTROL = 'choice pills';

    /** @var string|null The headline. */
    public ?string $title = null;

    /** @var string|null The line under the headline. */
    public ?string $subtitle = null;

    /** @var array[] The options, each `{label, value, sublabel?, selected?}`. */
    public array $options = [];

    /** @var string|null The label of the select-all control. */
    public ?string $selectAllLabel = null;

    /** @var string|null The confirm button's label, with the `{count}` token. */
    public ?string $confirmLabelTemplate = null;

    /** @var string|null The text the confirm posts, with the `{values}` / `{count}` tokens. */
    public ?string $confirmAction = null;

    /** @var string The confirm text of an owner question: the picked labels, joined by the widget. */
    public const string CONFIRM_ACTION_PICKED_VALUES = '{values}';

    /** Several picks: the confirm posts the picked labels. */
    public function isMultipleChoice(): bool
    {
        return true;
    }

    protected function getWrittenOptions(): array
    {
        return $this->options;
    }

    /**
     * The multi-choice widget of an owner question with several answers — the fields `askOwner`'s widget
     * carries: the question, one option per label (its value the label, its sublabel the description
     * when one exists) and the confirm that posts the picked labels.
     */
    public static function fromAskOwnerQuestion(AskOwnerQuestion $askOwnerQuestion): static
    {
        $multiChoiceWidget = new static();
        $multiChoiceWidget->question = $askOwnerQuestion->question;
        $multiChoiceWidget->options = array_map([self::class, 'buildOptionForAskOwnerOption'], $askOwnerQuestion->options);
        $multiChoiceWidget->confirmAction = self::CONFIRM_ACTION_PICKED_VALUES;
        return $multiChoiceWidget;
    }

    /**
     * One option of the widget from one `askOwner` option.
     *
     * @param AskOwnerQuestionOption $askOwnerOption
     * @return array<string, string>
     */
    public static function buildOptionForAskOwnerOption(AskOwnerQuestionOption $askOwnerOption): array
    {
        $option = ['label' => $askOwnerOption->label, 'value' => $askOwnerOption->label];
        if ($askOwnerOption->description !== null) {
            $option['sublabel'] = $askOwnerOption->description;
        }
        return $option;
    }

    /** The fields in block order (`askOwner`'s `question`, `options`, `confirmAction` keep theirs); a field left null is not written. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'question' => $this->question,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'options' => $this->options,
            'selectAllLabel' => $this->selectAllLabel,
            'confirmLabelTemplate' => $this->confirmLabelTemplate,
            'confirmAction' => $this->confirmAction,
        ]);
    }
}
