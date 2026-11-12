<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A link inside a `ComposedView` tree: text that opens an http(s) page in a new tab ("Open article ↗").
 * Any other scheme (`javascript:`, `data:`, `mailto:` …) is not a link: the frontend shows the text alone and reports
 * the node `failed` with `INVALID_PROP`. A link never sends a chat message.
 */
#[ComposedViewNodeProp(name: 'text', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], isRequired: true, description: 'the link text, in the owner\'s language')]
#[ComposedViewNodeProp(name: 'url', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the http(s) address it opens in a new tab')]
class LinkComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Link';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE];

    public const string SUMMARY = 'a text link that opens an http(s) page in a new tab';
}
