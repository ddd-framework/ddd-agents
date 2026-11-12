<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * Formatted text inside a `ComposedView` tree: a safe Markdown subset — paragraphs, emphasis, lists,
 * quotes, headings (rendered at most as large as a section title), tables and http(s) links that open in a new tab. Raw
 * HTML is shown as nothing, images and links with another scheme are dropped (the frontend renders them as their text).
 * No fenced code: three backticks would end the widget fence, so the reader refuses them anyway.
 */
#[ComposedViewNodeProp(name: 'markdown', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the formatted text (Markdown without HTML, images or code fences), in the owner\'s language')]
class MarkdownComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Markdown';

    /** The node's text prop is `markdown`. */
    public const string TEXT_CONTENT_PROP_NAME = 'markdown';

    public const array CATEGORIES = [ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'formatted text: a safe Markdown subset (no HTML, no images), http(s) links in a new tab';
}
