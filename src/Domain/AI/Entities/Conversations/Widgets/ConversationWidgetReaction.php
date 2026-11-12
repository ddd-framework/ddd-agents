<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * A row that may have reacted to a widget: the owner's TEXT row (a pill label, a typed answer) or an
 * owner-authored SIGNAL row (a widget click, a channel connection, an action confirm, a form widget's submit) —
 * exactly {@see AIConversationMessage::isOwnerAuthoredInbound()}. The loop's `MID_TURN_SIGNAL_REPLAY` copy of a click is
 * no reaction: its source is not owner-authored. Never stored separately; built from the row by
 * {@see ShownConversationWidgets::buildFor()}. Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class ConversationWidgetReaction extends ValueObject
{
    /** @var string The word a generic click sentence carries when the owner declined the widget it names. */
    public const string GENERIC_DECLINE_WORD = 'declined';

    /** @var int|null The reaction row's id; null for a row not persisted yet. */
    public ?int $messageId = null;

    /** @var int The reaction row's position in the conversation. */
    public int $indexInConversation = 0;

    /** @var string The row's message type: TEXT or SIGNAL. */
    public string $messageType = '';

    /** @var string|null The SIGNAL row's source (an {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource} value); null for a TEXT row. */
    public ?string $signalSource = null;

    /** @var string The row's text: the signal sentence or the owner's words. */
    public string $text = '';

    /**
     * @var string|null The type of the form widget whose submit the row is ({@see ConversationWidgetClasses::findFormWidgetTypeSubmittedByRow()}:
     * a form widget's signal or legacy owner text); null for a row that submits no form.
     */
    public ?string $formWidgetTypeSubmitted = null;

    /** The row the reaction was read from — for a widget's own reader that needs more than the fields above. */
    protected ?AIConversationMessage $reactionMessage = null;

    /**
     * The reaction of `$message`, or null when the row is no owner reaction ({@see AIConversationMessage::isOwnerAuthoredInbound()})
     * or carries no position in the conversation.
     */
    public static function fromMessage(AIConversationMessage $message): ?static
    {
        $indexInConversation = $message->indexInConversation ?? null;
        if ($indexInConversation === null || !$message->isOwnerAuthoredInbound()) {
            return null;
        }
        $conversationWidgetReaction = new static();
        $conversationWidgetReaction->messageId = isset($message->id) ? (int)$message->id : null;
        $conversationWidgetReaction->indexInConversation = (int)$indexInConversation;
        $conversationWidgetReaction->messageType = (string)($message->messageType ?? '');
        $conversationWidgetReaction->signalSource = ($message->messageType ?? null) === AIConversationMessage::MESSAGE_SIGNAL ? ($message->signalSource ?? null) : null;
        $conversationWidgetReaction->text = (string)($message->message ?? '');
        $conversationWidgetReaction->formWidgetTypeSubmitted = ConversationWidgetClasses::findFormWidgetTypeSubmittedByRow($message);
        $conversationWidgetReaction->reactionMessage = $message;
        return $conversationWidgetReaction;
    }

    /**
     * The owner's newest reaction among the rows of `$messages` (the highest index; of two at one index the first), null
     * without one.
     */
    public static function findNewestIn(AIConversationMessages $messages): ?self
    {
        $newestConversationWidgetReaction = null;
        foreach ($messages->getElements() as $message) {
            $conversationWidgetReaction = self::fromMessage($message);
            if ($conversationWidgetReaction !== null
                && ($newestConversationWidgetReaction === null || $conversationWidgetReaction->indexInConversation > $newestConversationWidgetReaction->indexInConversation)
            ) {
                $newestConversationWidgetReaction = $conversationWidgetReaction;
            }
        }
        return $newestConversationWidgetReaction;
    }

    /** Whether the row is the owner's submit of a form widget ({@see $formWidgetTypeSubmitted}). */
    public function isFormSubmit(): bool
    {
        return $this->formWidgetTypeSubmitted !== null;
    }

    public function getReactionMessage(): ?AIConversationMessage
    {
        return $this->reactionMessage;
    }

    /** The owner's TEXT row ({@see AIConversationMessage::isUserRoleOrOwnerAuthoredText()} — the only TEXT rows that are reactions). */
    public function isOwnerText(): bool
    {
        return $this->messageType === AIConversationMessage::MESSAGE_TEXT;
    }

    /** A SIGNAL row of the generic widget-click source `FRONTEND_WIDGET_CLICK`. */
    public function isWidgetClickSignal(): bool
    {
        return $this->messageType === AIConversationMessage::MESSAGE_SIGNAL
            && $this->signalSource === AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK;
    }

    /** Whether the row's text names the widget type (a substring, as every click sentence carries the wire name). */
    public function namesWidgetType(string $widgetType): bool
    {
        return $widgetType !== '' && str_contains($this->text, $widgetType);
    }

    /**
     * The generic decline rule: a widget click that
     * is no form submit, names the type and carries the word `declined` (case-sensitive).
     */
    public function declinesWidgetTypeByGenericRule(string $widgetType): bool
    {
        return !$this->isFormSubmit()
            && $this->isWidgetClickSignal()
            && $this->namesWidgetType($widgetType)
            && str_contains($this->text, self::GENERIC_DECLINE_WORD);
    }

    /**
     * The generic answer rule: a form submit
     * answers its own form widget and nothing else; a widget click naming the type answers it unless it declines; any
     * other owner TEXT row answers it; any other owner signal does not.
     */
    public function answersWidgetTypeByGenericRule(string $widgetType): bool
    {
        if ($this->isFormSubmit()) {
            return $widgetType === $this->formWidgetTypeSubmitted;
        }
        if ($this->isWidgetClickSignal()) {
            return $this->namesWidgetType($widgetType) && !str_contains($this->text, self::GENERIC_DECLINE_WORD);
        }
        return $this->isOwnerText();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->indexInConversation);
    }
}
