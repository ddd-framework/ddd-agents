<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Every widget shown in a conversation, with the owner's reaction rows after each — the one
 * view the widget readers ask instead of scanning the message set themselves. Built pure from a set of rows
 * ({@see buildFor()}): the conversation's loaded rows ({@see AIConversationMessages::buildShownWidgets()}) or the
 * window-independent widget and reaction rows the service loads (`AIConversation::findShownWidgets()`). Elements are
 * sorted by host row index, then block order. Reads nothing.
 *
 * A host row is any ASSISTANT row (the model's text, a tool row, a platform row); a block is a VALID block of any type
 * (`WidgetBlocks`), a type without a class included.
 *
 * @property ShownConversationWidget[] $elements
 * @method ShownConversationWidget[] getElements()
 * @method ShownConversationWidget|null first()
 * @phpstan-consistent-constructor
 */
class ShownConversationWidgets extends ObjectSet
{
    /** The view over `$messages`: one element per VALID block of an ASSISTANT row, each with the reactions after its row. */
    public static function buildFor(AIConversationMessages $messages): static
    {
        $rowsByIndex = $messages->getElements();
        usort($rowsByIndex, static fn (AIConversationMessage $a, AIConversationMessage $b): int => ($a->indexInConversation ?? PHP_INT_MIN) <=> ($b->indexInConversation ?? PHP_INT_MIN));
        $conversationWidgetReactions = [];
        foreach ($rowsByIndex as $message) {
            $conversationWidgetReaction = ConversationWidgetReaction::fromMessage($message);
            if ($conversationWidgetReaction !== null) {
                $conversationWidgetReactions[] = $conversationWidgetReaction;
            }
        }
        $shownConversationWidgets = new static();
        foreach ($rowsByIndex as $message) {
            if (($message->role ?? null) !== AIConversationMessage::ROLE_ASSISTANT) {
                continue;
            }
            foreach ($message->buildWidgetBlocks()->getElements() as $widgetBlock) {
                if ($widgetBlock->outcome !== WidgetBlock::OUTCOME_VALID) {
                    continue;
                }
                $shownConversationWidget = ShownConversationWidget::fromHostMessageBlock($message, $widgetBlock);
                $shownConversationWidget->setReactions(...self::findReactionsAfterIndex($conversationWidgetReactions, $shownConversationWidget->hostIndexInConversation));
                $shownConversationWidgets->add($shownConversationWidget);
            }
        }
        return $shownConversationWidgets;
    }

    /**
     * The reactions with an index above `$hostIndexInConversation`, index order; none for a host row without an index.
     *
     * @param ConversationWidgetReaction[] $conversationWidgetReactions index order
     * @return ConversationWidgetReaction[]
     */
    protected static function findReactionsAfterIndex(array $conversationWidgetReactions, ?int $hostIndexInConversation): array
    {
        if ($hostIndexInConversation === null) {
            return [];
        }
        $reactionsAfterIndex = [];
        foreach ($conversationWidgetReactions as $conversationWidgetReaction) {
            if ($conversationWidgetReaction->indexInConversation > $hostIndexInConversation) {
                $reactionsAfterIndex[] = $conversationWidgetReaction;
            }
        }
        return $reactionsAfterIndex;
    }

    /** Whether a widget of one of `$widgetTypes` was shown at all. */
    public function hasShownOfType(string ...$widgetTypes): bool
    {
        return $this->findNewestOfType(...$widgetTypes) !== null;
    }

    /**
     * The newest widget of one of `$widgetTypes` — the one on the host row with the highest index, the last of the type
     * in that row; a host row without an index is older than every row with one. Null when none was shown.
     */
    public function findNewestOfType(string ...$widgetTypes): ?ShownConversationWidget
    {
        $newestShownConversationWidget = null;
        foreach ($this->getElements() as $shownConversationWidget) {
            if ($shownConversationWidget->isOfType(...$widgetTypes)) {
                $newestShownConversationWidget = $shownConversationWidget;
            }
        }
        return $newestShownConversationWidget;
    }

    /**
     * The owner's answer on the newest widget of `$widgetType` by the generic rules: false when a reaction after
     * it declines the type ({@see ShownConversationWidget::isDeclinedByGenericRule()}), true when one answers it
     * ({@see ShownConversationWidget::wasAnsweredByGenericRule()}), null otherwise — and null without such a widget on
     * a host row with an index (a row without one is older than every row with one).
     */
    public function readOwnerAnswerOnNewestOfTypeByGenericRule(string $widgetType): ?bool
    {
        $newestShownConversationWidget = $this->findNewestOfType($widgetType);
        if ($newestShownConversationWidget?->hostIndexInConversation === null) {
            return null;
        }
        if ($newestShownConversationWidget->isDeclinedByGenericRule()) {
            return false;
        }
        return $newestShownConversationWidget->wasAnsweredByGenericRule() ? true : null;
    }

    /** The newest widget of one of `$widgetTypes` on a host row below `$beforeIndex`; null when none. */
    public function findNewestOfTypeBefore(int $beforeIndex, string ...$widgetTypes): ?ShownConversationWidget
    {
        $newestShownConversationWidget = null;
        foreach ($this->getElements() as $shownConversationWidget) {
            if ($shownConversationWidget->hostIndexInConversation !== null
                && $shownConversationWidget->hostIndexInConversation < $beforeIndex
                && $shownConversationWidget->isOfType(...$widgetTypes)
            ) {
                $newestShownConversationWidget = $shownConversationWidget;
            }
        }
        return $newestShownConversationWidget;
    }

    /**
     * The widgets on host rows above `$afterIndex` — with the current turn's initiator index, the widgets the turn
     * showed. The view holds no turn boundaries: the caller passes the initiator index it knows.
     */
    public function findShownAfterIndex(int $afterIndex): static
    {
        $shownAfterIndex = new static();
        foreach ($this->getElements() as $shownConversationWidget) {
            if ($shownConversationWidget->hostIndexInConversation !== null && $shownConversationWidget->hostIndexInConversation > $afterIndex) {
                $shownAfterIndex->add($shownConversationWidget);
            }
        }
        return $shownAfterIndex;
    }

    /** The widgets of one of `$widgetTypes` on host rows above `$afterIndex` that the owner has not answered ({@see ShownConversationWidget::wasAnswered()}). */
    public function findUnansweredOfTypeAfterIndex(int $afterIndex, string ...$widgetTypes): static
    {
        $unansweredAfterIndex = new static();
        foreach ($this->findShownAfterIndex($afterIndex)->getElements() as $shownConversationWidget) {
            if ($shownConversationWidget->isOfType(...$widgetTypes) && !$shownConversationWidget->wasAnswered()) {
                $unansweredAfterIndex->add($shownConversationWidget);
            }
        }
        return $unansweredAfterIndex;
    }

    /**
     * The written types (a `…Directive` suffix kept) of the widgets on TOOL rows above `$afterIndex`, first-appearance
     * order, repeats dropped — the scan half of `AIConversationMessages::findToolEmittedWidgetTypesInCurrentTurn()`.
     *
     * @return string[]
     */
    public function getWrittenTypesOfToolRowsAfter(int $afterIndex): array
    {
        $writtenWidgetTypes = [];
        foreach ($this->findShownAfterIndex($afterIndex)->getElements() as $shownConversationWidget) {
            $writtenWidgetType = $shownConversationWidget->widgetBlock?->getWrittenWidgetType();
            if ($writtenWidgetType !== null && $shownConversationWidget->isOnToolRow()) {
                $writtenWidgetTypes[] = $writtenWidgetType;
            }
        }
        return array_values(array_unique($writtenWidgetTypes));
    }
}
