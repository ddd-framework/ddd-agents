<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A site's small icon inside a `ComposedView` tree: the favicon of the domain of `url`, in front of a
 * source or a result row. A site without an icon, a failed load and the e-mail capture show the generic link glyph.
 */
#[ComposedViewNodeProp(name: 'url', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'a page address or a bare domain (wdr.de) whose site icon is shown')]
#[ComposedViewNodeProp(name: 'name', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the site name, read aloud and shown on hover')]
class FaviconComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Favicon';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE];

    public const string SUMMARY = 'the small site icon of a domain, in front of a source or a result';
}
