<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * The references of the agent's web research answer: the agent writes its answer as prose
 * and puts this widget under it with the pages it used — the frontend renders them as link chips with each site's
 * favicon. Data-complete. `answer`, `title` and `display` are the legacy fields of messages stored
 * before: a block with `answer` renders the former answer widget.
 */
class WebSearchResultsConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'WebSearchResults';

    /** @var bool Rendered as an image in the scheduled report mail ({@see ConversationWidget::EMAIL_RENDERABLE}). */
    public const bool EMAIL_RENDERABLE = true;

    public const string SUMMARY = 'References of the agent\'s own web research answer — the pages it used as link chips with site icons, placed under its prose answer. DATA-COMPLETE.';

    public const string WHEN_TO_USE = 'After a web research answer on a screen: the answer as prose first, then this widget on its own line with the pages the answer used. Never the answer inside the widget, never a page the tools did not return.';

    public const string OWNER_CONTROL = 'none (read-only)';

    /** @var string The search query (at most 300 characters). */
    public string $query = '';

    /** @var string|null A headline instead of the query (at most 160 characters). */
    public ?string $title = null;

    /** @var string|null The search provider (`PERPLEXITY`, `OPENAI`, `GEMINI`). */
    public ?string $provider = null;

    /** @var string|null `inline` or `canvas`; unset = inline. */
    public ?string $display = null;

    /** @var string|null Legacy: the answer as markdown; null in the references form (not written). */
    public ?string $answer = null;

    /** @var array[] The pages the answer used, each `{url, title?}`, at most 30. */
    public array $sources = [];

    /** The fields in block order; an optional field left null is not written. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'query' => $this->query,
            'title' => $this->title,
            'provider' => $this->provider,
            'display' => $this->display,
            'answer' => $this->answer,
            'sources' => $this->sources,
        ]);
    }
}
