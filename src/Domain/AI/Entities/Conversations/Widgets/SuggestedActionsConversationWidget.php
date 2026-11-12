<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * The model's suggested next owner messages as pills (the frontend component is `SuggestedActions`).
 * The frontend reads `options` as the `::SuggestedActions{options='…'}` ATTRIBUTE (a JSON string), not from a
 * fence payload: a ```widget block of this type renders nothing today.
 */
class SuggestedActionsConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'SuggestedActions';

    /** @var array[]|null The pills, each `{displayText, userAction}`. */
    public ?array $options = null;

    /** The fields in block order; an optional field left null is not written. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'options' => $this->options,
        ]);
    }
}
