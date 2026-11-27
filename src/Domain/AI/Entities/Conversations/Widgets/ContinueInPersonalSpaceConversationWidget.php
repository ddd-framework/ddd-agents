<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * A button that starts a NEW conversation in the owner's personal space with the same request — written by a tool
 * that refused because the current conversation is read by others (a shared space) and the needed data is private to
 * the owner. The click is handled by the frontend alone (start in the personal space, post `request` there); nothing
 * returns to the shared conversation, so the private result never enters it. The button label is the frontend's own
 * text.
 */
class ContinueInPersonalSpaceConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'ContinueInPersonalSpace';

    public const string SUMMARY = 'A button that continues the owner\'s request in a new conversation of the owner\'s personal space.';

    public const string WHEN_TO_USE = 'Written by a tool only, when private data of the owner may not be shown in a conversation others read.';

    public const string OWNER_CONTROL = 'button';

    /** @var string Why the request cannot run here (one sentence, shown above the button) */
    public string $reason = '';

    /** @var string The owner's request, posted as the first message of the new conversation */
    public string $request = '';

    public static function forRequest(string $request, string $reason): static
    {
        $continueWidget = new static();
        $continueWidget->request = mb_substr(trim($request), 0, 2000);
        $continueWidget->reason = $reason;
        return $continueWidget;
    }

    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'reason' => $this->reason,
            'request' => $this->request,
        ]);
    }
}
