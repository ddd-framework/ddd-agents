<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * What the owner answered on an owner question ({@see OwnerQuestionConversationWidget}): the option
 * labels they picked — a pill click posts the label as the owner's text, the multi-choice confirm the picked labels
 * joined by `, ` — with each picked option's catalogue meaning and description, and the question's catalogue key. A
 * text that is no pick (the owner typed an answer of their own) is an answer without labels ({@see isPick()}). Read by
 * the widget's own reader; reads nothing.
 */
class OwnerQuestionPick extends ConversationWidgetAnswer
{
    /** @var string|null The question's catalogue key (`draftApproval`, or one the application added); null for a question without one. */
    public ?string $questionKey = null;

    /** @var string[] The picked labels as the widget carries them, pick order, each once; empty when the owner typed. */
    public array $labels = [];

    /** @var string[] The catalogue meanings of the picked options, pick order — only the options that carry one. */
    public array $meanings = [];

    /** @var string[] The descriptions of the picked options, pick order — only the options that carry one. */
    public array $descriptions = [];

    /** @var int[] The zero-based positions of the picked options in the widget, pick order. */
    public array $optionPositions = [];

    /**
     * @var string The owner's text, trimmed, as written — the suffix that names another space included; empty for a
     * click signal.
     */
    public string $ownerText = '';

    /**
     * @var int|null The key of the agent space the text named in its suffix
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames::getOwnerTextSuffixPatternOfAnotherSpace()})
     * — a pill clicked on the widget of another space; the suffix was stripped before the labels were matched. Null
     * for a text without it.
     */
    public ?int $foreignWidgetSelfHydrationKey = null;

    /** Whether the owner picked one or more of the widget's options (rather than typing an answer of their own). */
    public function isPick(): bool
    {
        return $this->labels !== [];
    }
}
