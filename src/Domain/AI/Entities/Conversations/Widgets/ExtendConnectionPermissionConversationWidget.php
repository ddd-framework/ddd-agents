<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * A button that opens the connection settings to extend a permission — written by the connection gate when a step
 * needs a level the connection does not have, or scopes the provider has not granted yet (incremental consent). The
 * click is handled by the frontend alone (the settings page of `connectionId`, with `capability` and `level`
 * preselected); the level takes effect only after the owner raised it there (and consented at the provider).
 */
class ExtendConnectionPermissionConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'ExtendConnectionPermission';

    public const string SUMMARY = 'A button that opens the connection settings to extend a permission the step needs.';

    public const string WHEN_TO_USE = 'Written by a tool only, when a connection lacks the level or the provider permission a step needs.';

    public const string OWNER_CONTROL = 'button';

    /** @var string What is missing (one sentence, shown above the button) */
    public string $reason = '';

    /** @var int The connection to extend */
    public int $connectionId = 0;

    /** @var string The capability to extend (MAIL, CALENDAR, CONTACTS, FILES, CHAT) */
    public string $capability = '';

    /** @var string The level the step needs */
    public string $level = '';

    public static function forConnection(int $connectionId, string $capability, string $level, string $reason): static
    {
        $extendWidget = new static();
        $extendWidget->connectionId = $connectionId;
        $extendWidget->capability = $capability;
        $extendWidget->level = $level;
        $extendWidget->reason = $reason;
        return $extendWidget;
    }

    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'reason' => $this->reason,
            'connectionId' => $this->connectionId,
            'capability' => $this->capability,
            'level' => $this->level,
        ]);
    }
}
