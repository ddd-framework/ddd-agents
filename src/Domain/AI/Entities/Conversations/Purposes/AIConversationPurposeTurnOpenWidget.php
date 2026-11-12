<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The widget a purpose has the platform write when a turn opens ({@see AIConversationPurpose::buildWidgetAtTurnOpen()}):
 * the widget, the domain reason the platform-widget writer stamps as its origin (`emittedBy: platform:<reason>`), and
 * the SIGNAL text that tells the model the widget is in the chat — appended only when the writer actually wrote it.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationPurposeTurnOpenWidget extends ValueObject
{
    /** @var ConversationWidget The widget to write. */
    public ConversationWidget $conversationWidget;

    /** @var string The platform reason of the widget's origin. */
    public string $platformReason;

    /** @var string The signal text appended for the model after a written widget. */
    public string $signalText;

    /**
     * @param ConversationWidget $conversationWidget
     * @param string $platformReason
     * @param string $signalText
     * @return self
     */
    public static function of(ConversationWidget $conversationWidget, string $platformReason, string $signalText): self
    {
        $turnOpenWidget = new static();
        $turnOpenWidget->conversationWidget = $conversationWidget;
        $turnOpenWidget->platformReason = $platformReason;
        $turnOpenWidget->signalText = $signalText;
        return $turnOpenWidget;
    }
}
