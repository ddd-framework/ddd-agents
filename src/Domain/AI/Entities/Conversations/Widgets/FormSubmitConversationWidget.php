<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;

/**
 * A form widget whose owner's submit arrives as a row of its own form: a signal or an owner text
 * the widget composed from its submit contract, which the widget's class recognises and which answers this widget type
 * and no other. The AI core imports no domain widget; it asks the registry which form a row submits
 * ({@see ConversationWidgetClasses::findFormWidgetTypeSubmittedByRow()}) — for the reaction's submit field
 * ({@see ConversationWidgetReaction::$formWidgetTypeSubmitted}) and the owner-form turn start
 * (`AIConversationMessages::isOwnerFormSubmitInitiator()`). Implemented by a {@see ConversationWidget} subclass.
 */
interface FormSubmitConversationWidget
{
    /** Whether `$message` is the owner's submit of this form widget. */
    public static function isFormSubmitRow(AIConversationMessage $message): bool;
}
