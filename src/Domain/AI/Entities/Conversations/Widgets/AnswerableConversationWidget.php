<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * A widget that reads the owner's answer from its own reaction rows: one reader per widget, which
 * replaces the diverging readers on the message set. Implemented by a {@see ConversationWidget} subclass restored from
 * its stored block ({@see ConversationWidget::fromWidgetBlock()}); the reaction sentences it reads are pinned by its
 * contract fixture (`<name>-widget-contract.json`, checked by a test of the application). A widget type
 * without this interface is read by the generic rules of {@see ShownConversationWidget}.
 */
interface AnswerableConversationWidget
{
    /**
     * Whether `$conversationWidgetReaction` reacts to THIS widget: it names the widget's type and, when the
     * widget has one, its identifying value (a row id, an address).
     */
    public function isReactionToThisWidget(ConversationWidgetReaction $conversationWidgetReaction): bool;

    /** The owner's answer the reaction carries, or null when it carries none (a decline, an unrelated text). */
    public function readAnswer(ConversationWidgetReaction $conversationWidgetReaction): ?ConversationWidgetAnswer;

    /** Whether the reaction declines the widget. */
    public function isDeclined(ConversationWidgetReaction $conversationWidgetReaction): bool;
}
