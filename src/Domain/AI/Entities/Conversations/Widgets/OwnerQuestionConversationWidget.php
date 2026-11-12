<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;

/**
 * The owner question's widget: the `askOwner` question and the platform's sets question, as
 * single-choice pills ({@see QuickRepliesConversationWidget}) or a multi-choice widget
 * ({@see MultiChoiceConversationWidget}). The question text rides in the block for the platform's read-back and stands
 * as the prose line above it (the widgets render the options only). No wire type of its own.
 *
 * It reads its own answer (pinned by `quick-replies-widget-contract.json` and
 * `multi-choice-widget-contract.json`): a click posts the picked label(s) as the owner's TEXT, so the answer is the
 * owner's text matched against the option labels ({@see buildPickFromOwnerText()}) — the whole text first, then the
 * multi-choice join split, after the client's suffix of a widget of another space is stripped.
 * Which rows answer it and which decline it is exactly the generic rule
 * ({@see ConversationWidgetReaction::answersWidgetTypeByGenericRule()}, {@see ConversationWidgetReaction::declinesWidgetTypeByGenericRule()}):
 * any owner text answers it, a pick or not. The owner's PICK is the first owner text after the widget
 * ({@see readPickFromFirstOwnerText()}) — a later text answers a later question.
 */
abstract class OwnerQuestionConversationWidget extends ConversationWidget implements AnswerableConversationWidget
{
    public const bool AWAITS_OWNER_INPUT = true;

    /** @var string|null The question text the options answer — read back by the platform, ignored by the widget. */
    public ?string $question = null;

    /** @var (string|null)[]|null One description per option (null where an option has none); `askOwner` writes it on the pills only. */
    public ?array $descriptions = null;

    /** @var string|null The question's catalogue key ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionCatalogue}); `askOwner` writes it on the pills only. */
    public ?string $questionKey = null;

    /** @var string[]|null What each option means for the catalogue key, one entry per option; written whenever the key is. */
    public ?array $optionMeanings = null;

    /**
     * The widget of a recorded owner question: `MultiChoice` for a multiple choice,
     * `QuickReplies` otherwise. Its row is byte-identical to {@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool::buildWidgetMarkdown()}
     * apart from the writer's `emittedBy`.
     */
    public static function forAskOwnerQuestion(AskOwnerQuestion $askOwnerQuestion): self
    {
        return $askOwnerQuestion->multiSelect
            ? MultiChoiceConversationWidget::fromAskOwnerQuestion($askOwnerQuestion)
            : QuickRepliesConversationWidget::fromAskOwnerQuestion($askOwnerQuestion);
    }

    /** The question text stands right above its options. */
    public function getProseAboveTheBlock(): ?string
    {
        return $this->question;
    }

    /** Whether the owner may pick several options — the confirm then posts the picked labels joined by {@see AskOwnerTool::MULTI_PICK_SEPARATOR}. */
    abstract public function isMultipleChoice(): bool;

    /**
     * The options as the block carries them: a label string (the pills) or an object with `label` and `sublabel` (the
     * multi-choice widget).
     *
     * @return array<int|string, mixed>
     */
    abstract protected function getWrittenOptions(): array;

    /**
     * The option labels, trimmed, option order — an option object's `label`, a plain option itself.
     *
     * @return string[]
     */
    public function getOptionLabels(): array
    {
        $optionLabels = [];
        foreach ($this->getWrittenOptions() as $writtenOption) {
            $optionLabels[] = trim((string)(is_array($writtenOption) ? ($writtenOption['label'] ?? '') : $writtenOption));
        }
        return $optionLabels;
    }

    /**
     * The option descriptions, trimmed, option order ('' where an option has none): the `descriptions` field when the
     * block carries one, else each option object's `sublabel`.
     *
     * @return string[]
     */
    public function getOptionDescriptions(): array
    {
        if ($this->descriptions !== null) {
            return array_values(array_map(static fn (mixed $description): string => trim((string)$description), $this->descriptions));
        }
        $optionDescriptions = [];
        foreach ($this->getWrittenOptions() as $writtenOption) {
            $optionDescriptions[] = trim((string)(is_array($writtenOption) ? ($writtenOption['sublabel'] ?? '') : ''));
        }
        return $optionDescriptions;
    }

    /**
     * The catalogue meanings, option order; empty when the block carries none.
     *
     * @return string[]
     */
    public function getOptionMeanings(): array
    {
        return $this->optionMeanings !== null ? array_values(array_map('strval', $this->optionMeanings)) : [];
    }

    /** The catalogue key, trimmed; null for a question without one. */
    public function getQuestionKey(): ?string
    {
        $questionKey = trim((string)$this->questionKey);
        return $questionKey !== '' ? $questionKey : null;
    }

    /**
     * Whether the block carries at least one option with a non-empty label — an `options` field that is no list, or a
     * list of empty labels, offers the owner nothing to pick.
     */
    public function hasOptionLabels(): bool
    {
        return array_filter($this->getOptionLabels(), static fn (string $optionLabel): bool => $optionLabel !== '') !== [];
    }

    /** The owner's text, or a widget click naming the type — never a form widget's submit. */
    public function isReactionToThisWidget(ConversationWidgetReaction $conversationWidgetReaction): bool
    {
        if ($conversationWidgetReaction->isFormSubmit()) {
            return false;
        }
        return $conversationWidgetReaction->isOwnerText()
            || ($conversationWidgetReaction->isWidgetClickSignal() && $conversationWidgetReaction->namesWidgetType(static::WIDGET_TYPE));
    }

    /**
     * The owner's answer on this widget: for an owner text the pick it carries ({@see buildPickFromOwnerText()}), an
     * answer without labels when the owner typed; for a widget click naming the type an answer without labels. Null for
     * a row the generic rule does not count as an answer (a decline, another signal, an empty text).
     */
    public function readAnswer(ConversationWidgetReaction $conversationWidgetReaction): ?OwnerQuestionPick
    {
        if (!$conversationWidgetReaction->answersWidgetTypeByGenericRule(static::WIDGET_TYPE)) {
            return null;
        }
        if ($conversationWidgetReaction->isOwnerText()) {
            return $this->buildPickFromOwnerText($conversationWidgetReaction->text, $conversationWidgetReaction->indexInConversation);
        }
        $ownerQuestionPick = new OwnerQuestionPick();
        $ownerQuestionPick->reactionIndexInConversation = $conversationWidgetReaction->indexInConversation;
        $ownerQuestionPick->questionKey = $this->getQuestionKey();
        return $ownerQuestionPick;
    }

    public function isDeclined(ConversationWidgetReaction $conversationWidgetReaction): bool
    {
        return $conversationWidgetReaction->declinesWidgetTypeByGenericRule(static::WIDGET_TYPE);
    }

    /**
     * The owner's PICK on this widget: the FIRST non-empty owner text among `$conversationWidgetReactions` (the reactions
     * after the widget, index order — `ShownConversationWidget::getReactions()`) when it picks options; null when that
     * text is no pick (the owner typed — a later text answers a later question, never this one) or there is none. The
     * multi-choice join is split and the suffix stripped.
     */
    public function readPickFromFirstOwnerText(ConversationWidgetReaction ...$conversationWidgetReactions): ?OwnerQuestionPick
    {
        foreach ($conversationWidgetReactions as $conversationWidgetReaction) {
            if (!$conversationWidgetReaction->isOwnerText() || trim($conversationWidgetReaction->text) === '') {
                continue;
            }
            $ownerQuestionPick = $this->buildPickFromOwnerText($conversationWidgetReaction->text, $conversationWidgetReaction->indexInConversation);
            return $ownerQuestionPick !== null && $ownerQuestionPick->isPick() ? $ownerQuestionPick : null;
        }
        return null;
    }

    /**
     * The owner's text read against the options: the suffix that names a space stripped when it names
     * this widget's space (the click on a widget of another space appends it); then the WHOLE text matched against
     * one label (a label may itself carry `, `), and only for a multiple choice that fails the text split at
     * {@see AskOwnerTool::MULTI_PICK_SEPARATOR} with every part a label. A label matches case-insensitively, trimmed,
     * without trailing ` .!`. A text that matches no way is an answer without labels; null for an empty text.
     */
    public function buildPickFromOwnerText(string $ownerText, ?int $reactionIndexInConversation = null): ?OwnerQuestionPick
    {
        $ownerText = trim($ownerText);
        if ($ownerText === '') {
            return null;
        }
        $ownerQuestionPick = new OwnerQuestionPick();
        $ownerQuestionPick->reactionIndexInConversation = $reactionIndexInConversation;
        $ownerQuestionPick->questionKey = $this->getQuestionKey();
        $ownerQuestionPick->ownerText = $ownerText;
        $pickText = $ownerText;
        $ownerTextSuffixPatternOfAnotherSpace = WidgetBlockFieldNames::getOwnerTextSuffixPatternOfAnotherSpace();
        if ($ownerTextSuffixPatternOfAnotherSpace !== null && $this->widgetSelfHydrationKey !== null
            && preg_match($ownerTextSuffixPatternOfAnotherSpace, $ownerText, $suffixMatch) === 1 && (int)$suffixMatch[1] === $this->widgetSelfHydrationKey
        ) {
            $ownerQuestionPick->foreignWidgetSelfHydrationKey = $this->widgetSelfHydrationKey;
            $pickText = trim(substr($ownerText, 0, -strlen($suffixMatch[0])));
        }
        $pickedPositions = $this->findPickedOptionPositions($pickText);
        if ($pickedPositions === []) {
            return $ownerQuestionPick;
        }
        $optionLabels = $this->getOptionLabels();
        $optionMeanings = $this->getOptionMeanings();
        $optionDescriptions = $this->getOptionDescriptions();
        foreach ($pickedPositions as $pickedPosition) {
            $ownerQuestionPick->optionPositions[] = $pickedPosition;
            $ownerQuestionPick->labels[] = $optionLabels[$pickedPosition];
            if (($optionMeanings[$pickedPosition] ?? '') !== '' && $optionMeanings[$pickedPosition] !== '0') {
                $ownerQuestionPick->meanings[] = $optionMeanings[$pickedPosition];
            }
            if (($optionDescriptions[$pickedPosition] ?? '') !== '' && $optionDescriptions[$pickedPosition] !== '0') {
                $ownerQuestionPick->descriptions[] = $optionDescriptions[$pickedPosition];
            }
        }
        return $ownerQuestionPick;
    }

    /**
     * The positions of the options `$pickText` picks, pick order, each once; empty when it picks none.
     *
     * @return int[]
     */
    protected function findPickedOptionPositions(string $pickText): array
    {
        $positionsByNormalizedLabel = [];
        foreach ($this->getOptionLabels() as $optionPosition => $optionLabel) {
            $positionsByNormalizedLabel[static::normalizeLabelText($optionLabel)] = $optionPosition;
        }
        $wholeTextPosition = $positionsByNormalizedLabel[static::normalizeLabelText($pickText)] ?? null;
        if ($wholeTextPosition !== null) {
            return [$wholeTextPosition];
        }
        if (!$this->isMultipleChoice()) {
            return [];
        }
        $pickedPositions = [];
        foreach (explode(AskOwnerTool::MULTI_PICK_SEPARATOR, $pickText) as $pickTextPart) {
            $partPosition = $positionsByNormalizedLabel[static::normalizeLabelText($pickTextPart)] ?? null;
            if ($partPosition === null) {
                return [];
            }
            $pickedPositions[] = $partPosition;
        }
        return array_values(array_unique($pickedPositions));
    }

    /** A label or the owner's text as the match compares it: trimmed, lower case, without trailing spaces, dots and exclamation marks. */
    protected static function normalizeLabelText(string $labelText): string
    {
        return rtrim(mb_strtolower(trim($labelText)), ' .!');
    }
}
