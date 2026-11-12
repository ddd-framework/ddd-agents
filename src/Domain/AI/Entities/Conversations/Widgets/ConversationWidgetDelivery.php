<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * Where a widget a tool or the platform hands to the chat went: WRITTEN into the chat as its own
 * row (the host message); not written because there is NO CHAT to write into (an eval harness call, no live
 * conversation, a blank block); not written because the call runs in a DELEGATION CHILD
 * (a child's widgets are never shown — its final result is what the main turn sees); or not written because
 * the owner is on the PHONE LINE (a call shows no screen — the channel decides, never the
 * purpose name). In every case without a row the caller dictates the block in its result instead, has it spoken, or
 * writes nothing. The writer decides; the caller reads the answer to pick its note.
 *
 * @phpstan-consistent-constructor
 */
class ConversationWidgetDelivery extends ValueObject
{
    public const string TYPE_WRITTEN = 'written';
    public const string TYPE_NO_CHAT = 'noChat';
    public const string TYPE_DELEGATION_CHILD = 'delegationChild';
    public const string TYPE_PHONE_LINE = 'phoneLine';

    #[Choice(choices: [self::TYPE_WRITTEN, self::TYPE_NO_CHAT, self::TYPE_DELEGATION_CHILD, self::TYPE_PHONE_LINE])]
    public string $type = self::TYPE_NO_CHAT;

    /** The row the widget was written into; null unless WRITTEN. */
    public ?AIConversationMessage $hostMessage = null;

    public static function written(AIConversationMessage $hostMessage): static
    {
        $conversationWidgetDelivery = new static();
        $conversationWidgetDelivery->type = self::TYPE_WRITTEN;
        $conversationWidgetDelivery->hostMessage = $hostMessage;
        return $conversationWidgetDelivery;
    }

    public static function noChat(): static
    {
        return new static();
    }

    /** The call runs in a delegation child: no row anywhere — not in the child, not in the root. */
    public static function delegationChild(): static
    {
        $conversationWidgetDelivery = new static();
        $conversationWidgetDelivery->type = self::TYPE_DELEGATION_CHILD;
        return $conversationWidgetDelivery;
    }

    /** The owner is on a call over the phone line: no row — the call transcript shows no widget. */
    public static function phoneLine(): static
    {
        $conversationWidgetDelivery = new static();
        $conversationWidgetDelivery->type = self::TYPE_PHONE_LINE;
        return $conversationWidgetDelivery;
    }

    public static function ofHostMessage(?AIConversationMessage $hostMessage): self
    {
        return $hostMessage !== null ? self::written($hostMessage) : self::noChat();
    }

    public function isWritten(): bool
    {
        return $this->type === self::TYPE_WRITTEN;
    }

    public function isOnThePhoneLine(): bool
    {
        return $this->type === self::TYPE_PHONE_LINE;
    }

    /**
     * The note for ONE fact a tool meant to post into the chat itself (a platform notice line) when this delivery wrote
     * no row: the model states the fact, in the form the delivery allows — spoken on the phone line, in its result in a
     * delegation child, in its own words where no chat exists. Null when the row was written (the caller's "posted"
     * note stands). The truthful counterpart of every "POSTED — write nothing" note (the widget
     * twin is {@see ConversationWidget::buildDictationNote()}).
     */
    public function buildNoteForFactNotWritten(string $factSentence): ?string
    {
        return match ($this->type) {
            self::TYPE_WRITTEN => null,
            self::TYPE_PHONE_LINE => 'NOT POSTED — the owner is on a phone call, so nothing was written into the chat: say this '
                . 'yourself in ONE short spoken sentence, in the owner\'s language: "' . $factSentence . '"',
            self::TYPE_DELEGATION_CHILD => 'NOT POSTED — this call runs in a delegated sub-task, so nothing reached the owner: '
                . 'put this fact into your result in one sentence, so the main conversation tells the owner: "' . $factSentence . '"',
            default => 'NOT POSTED — nothing was written into a chat here: tell the owner yourself in ONE short sentence, in '
                . 'their language: "' . $factSentence . '"',
        };
    }
}
