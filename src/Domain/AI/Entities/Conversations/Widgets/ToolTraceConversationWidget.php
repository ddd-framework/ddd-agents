<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * One tool step the frontend shows in the chat; both fields are optional, the name falls back to
 * "Tool".
 */
class ToolTraceConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'ToolTrace';

    /** @var string|null The tool's display name. */
    public ?string $name = null;

    /** @var mixed The tool's input; read only for `Navigate` (`input.destination`). */
    public mixed $input = null;

    /** The fields in block order; an optional field left null is not written. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'name' => $this->name,
            'input' => $this->input,
        ]);
    }
}
