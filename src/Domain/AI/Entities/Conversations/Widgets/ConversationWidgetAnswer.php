<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the owner answered on one widget, read by the widget's own class from a reaction row
 * ({@see AnswerableConversationWidget::readAnswer()}). Each answerable class returns its own subclass
 * (`OwnerQuestionPick`, an application's own answer classes) and narrows the return type
 * of its `readAnswer()` to it. Reads nothing.
 */
abstract class ConversationWidgetAnswer extends ValueObject
{
    /** @var int|null The position of the reaction row the answer was read from. */
    public ?int $reactionIndexInConversation = null;
}
