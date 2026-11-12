<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * The result of `searchConversations` — data-complete: the query and the grouped hits the model copies; the widget
 * never re-runs the search.
 */
class ConversationSearchResultsConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'ConversationSearchResults';

    public const string SUMMARY = 'Grouped cross-conversation recall results from `searchConversations`. DATA-COMPLETE; ≤5 groups inline, more → canvas.';

    public const string WHEN_TO_USE = 'After calling `searchConversations` — render the matches as this widget instead of re-listing them in prose. Few matches → they render inline; many → they open in the canvas side panel automatically (or set `display`). Skip for a single in-conversation recall (`recallConversation`) — that\'s the current chat, answer inline in prose.';

    /** @var string The search query (at most 200 characters). */
    public string $query = '';

    /** @var string|null A headline instead of the query line (at most 120 characters). */
    public ?string $title = null;

    /** @var string|null `inline` or `canvas`; unset = inline up to five groups, the canvas above. */
    public ?string $display = null;

    /** @var array[] One group per conversation (`conversationId`, `title`, `summary`, the dates, `hits`), at most 20. */
    public array $groups = [];

    /** The fields in block order; an optional field left null is not written. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'query' => $this->query,
            'title' => $this->title,
            'display' => $this->display,
            'groups' => $this->groups,
        ]);
    }
}
