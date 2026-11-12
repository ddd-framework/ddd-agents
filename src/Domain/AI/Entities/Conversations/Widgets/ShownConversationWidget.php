<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One widget shown in the conversation: a VALID block of an ASSISTANT row — the model's text or a
 * tool or platform row — with its host row, its type, its position among the blocks of that type in the row, and the
 * owner's reaction rows after it. Identified by host row + type + {@see $ordinalAmongBlocksOfType}. Built
 * by {@see ShownConversationWidgets::buildFor()}; reads nothing.
 *
 * Two kinds of reader: a type whose class implements {@see AnswerableConversationWidget} is read by that class
 * ({@see readNewestAnswer()}); every other type by the generic rules the message set used to apply by wire type
 * ({@see wasAnsweredByGenericRule()}, {@see isDeclinedByGenericRule()}). {@see wasAnswered()} and {@see isDeclined()}
 * pick the reader.
 *
 * @phpstan-consistent-constructor
 */
class ShownConversationWidget extends ValueObject
{
    /** @var WidgetBlock|null The block as parsed from the host row. */
    public ?WidgetBlock $widgetBlock = null;

    /**
     * @var string The widget's type: the canonical type of its class ({@see WidgetBlock::getCanonicalWidgetType()}),
     * or for a type without a class the written type without the legacy `…Directive` suffix.
     */
    public string $widgetType = '';

    /** @var int Position among the host row's VALID blocks of this type, message order ({@see WidgetBlock::$ordinalAmongBlocksOfType}). */
    public int $ordinalAmongBlocksOfType = 0;

    /** @var int|null The host row's id; null for a row not persisted yet. */
    public ?int $hostMessageId = null;

    /** @var int|null The host row's position in the conversation; null for a row that has none yet. */
    public ?int $hostIndexInConversation = null;

    /** @var string|null The host row's message type (TEXT for the model's text, TOOL_MESSAGE for a written widget). */
    public ?string $hostMessageType = null;

    /** @var string|null The host row's author type (ASSISTANT, AGENT, TOOL). */
    public ?string $hostAuthorType = null;

    /** @var ConversationWidgetReaction[] The owner's reaction rows after the host row, index order. */
    protected array $reactions = [];

    /** The widget `$widgetBlock` stands for in `$hostMessage`. */
    public static function fromHostMessageBlock(AIConversationMessage $hostMessage, WidgetBlock $widgetBlock): static
    {
        $shownConversationWidget = new static();
        $shownConversationWidget->widgetBlock = $widgetBlock;
        $shownConversationWidget->widgetType = $widgetBlock->getCanonicalWidgetType() ?? (string)$widgetBlock->getWidgetTypeWithoutLegacySuffix();
        $shownConversationWidget->ordinalAmongBlocksOfType = $widgetBlock->ordinalAmongBlocksOfType;
        $shownConversationWidget->hostMessageId = isset($hostMessage->id) ? (int)$hostMessage->id : null;
        $hostIndexInConversation = $hostMessage->indexInConversation ?? null;
        $shownConversationWidget->hostIndexInConversation = $hostIndexInConversation !== null ? (int)$hostIndexInConversation : null;
        $shownConversationWidget->hostMessageType = $hostMessage->messageType ?? null;
        $shownConversationWidget->hostAuthorType = $hostMessage->authorType ?? null;
        return $shownConversationWidget;
    }

    /** The reaction rows after the host row — set once by {@see ShownConversationWidgets::buildFor()}. */
    public function setReactions(ConversationWidgetReaction ...$conversationWidgetReactions): void
    {
        $this->reactions = $conversationWidgetReactions;
    }

    /**
     * The owner's reaction rows after the host row, index order — to this widget or to anything else; the readers
     * decide which ones concern it.
     *
     * @return ConversationWidgetReaction[]
     */
    public function getReactions(): array
    {
        return $this->reactions;
    }

    /** Whether the block is of one of `$widgetTypes`, plain or with the legacy suffix ({@see WidgetBlock::isOfType()}). */
    public function isOfType(string ...$widgetTypes): bool
    {
        foreach ($widgetTypes as $widgetType) {
            if ($this->widgetBlock?->isOfType($widgetType) === true) {
                return true;
            }
        }
        return false;
    }

    /** Whether the host row is a row a tool or the platform wrote (a TOOL_MESSAGE authored by TOOL) — a row question, not an origin. */
    public function isOnToolRow(): bool
    {
        return $this->hostMessageType === AIConversationMessage::MESSAGE_TOOL_MESSAGE
            && $this->hostAuthorType === AIConversationMessage::AUTHOR_TOOL;
    }

    /**
     * Who wrote the block: its `emittedBy` — but only on a TOOL_MESSAGE row, as
     * {@see AIConversationMessage::buildWidgetBlockOrigins()} reads it; on any other row the block reads as the model.
     */
    public function getOrigin(): ConversationWidgetOrigin
    {
        if ($this->widgetBlock === null || $this->hostMessageType !== AIConversationMessage::MESSAGE_TOOL_MESSAGE) {
            return ConversationWidgetOrigin::model();
        }
        return $this->widgetBlock->getOrigin();
    }

    /** The widget restored from its block through its class — the envelope's typed widget ({@see WidgetBlock::buildWidget()}); null for a type without a class. */
    public function buildWidget(): ?ConversationWidget
    {
        return $this->widgetBlock?->buildWidget();
    }

    /** Whether the widget's class reads its own answer ({@see AnswerableConversationWidget}). */
    public function isAnswerable(): bool
    {
        return $this->buildWidget() instanceof AnswerableConversationWidget;
    }

    /**
     * The owner's answer from the NEWEST reaction this widget's class reads one from ({@see AnswerableConversationWidget::isReactionToThisWidget()},
     * {@see AnswerableConversationWidget::readAnswer()}); null for a widget without such a class or without an answer.
     */
    public function readNewestAnswer(): ?ConversationWidgetAnswer
    {
        $conversationWidget = $this->buildWidget();
        if (!$conversationWidget instanceof AnswerableConversationWidget) {
            return null;
        }
        foreach (array_reverse($this->reactions) as $conversationWidgetReaction) {
            if (!$conversationWidget->isReactionToThisWidget($conversationWidgetReaction)) {
                continue;
            }
            $conversationWidgetAnswer = $conversationWidget->readAnswer($conversationWidgetReaction);
            if ($conversationWidgetAnswer !== null) {
                return $conversationWidgetAnswer;
            }
        }
        return null;
    }

    /** Whether the owner answered the widget and did not decline it — its class's reader, else the generic rule. */
    public function wasAnswered(): bool
    {
        if ($this->isAnswerable()) {
            return !$this->isDeclined() && $this->readNewestAnswer() !== null;
        }
        return $this->wasAnsweredByGenericRule();
    }

    /** Whether the owner declined the widget — its class's reader, else the generic rule. */
    public function isDeclined(): bool
    {
        $conversationWidget = $this->buildWidget();
        if (!$conversationWidget instanceof AnswerableConversationWidget) {
            return $this->isDeclinedByGenericRule();
        }
        foreach ($this->reactions as $conversationWidgetReaction) {
            if ($conversationWidget->isReactionToThisWidget($conversationWidgetReaction) && $conversationWidget->isDeclined($conversationWidgetReaction)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The generic answer rule over every reaction after the widget: answered when a reaction answers the type
     * ({@see ConversationWidgetReaction::answersWidgetTypeByGenericRule()}) and none declines it.
     */
    public function wasAnsweredByGenericRule(): bool
    {
        if ($this->isDeclinedByGenericRule()) {
            return false;
        }
        foreach ($this->reactions as $conversationWidgetReaction) {
            if ($conversationWidgetReaction->answersWidgetTypeByGenericRule($this->widgetType)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The generic decline rule over every reaction after the widget:
     * a widget click naming the type with the word `declined` ({@see ConversationWidgetReaction::declinesWidgetTypeByGenericRule()}).
     */
    public function isDeclinedByGenericRule(): bool
    {
        foreach ($this->reactions as $conversationWidgetReaction) {
            if ($conversationWidgetReaction->declinesWidgetTypeByGenericRule($this->widgetType)) {
                return true;
            }
        }
        return false;
    }

    /** Whether the owner wrote a TEXT row after the widget. */
    public function ownerWroteAfterIt(): bool
    {
        foreach ($this->reactions as $conversationWidgetReaction) {
            if ($conversationWidgetReaction->isOwnerText()) {
                return true;
            }
        }
        return false;
    }

    public function uniqueKey(): string
    {
        $hostKey = $this->hostMessageId !== null ? 'id' . $this->hostMessageId
            : ($this->hostIndexInConversation !== null ? 'index' . $this->hostIndexInConversation : 'object' . spl_object_id($this));
        return self::uniqueKeyStatic($hostKey . '-' . (string)($this->widgetBlock !== null ? $this->widgetBlock->ordinal : 0));
    }
}
