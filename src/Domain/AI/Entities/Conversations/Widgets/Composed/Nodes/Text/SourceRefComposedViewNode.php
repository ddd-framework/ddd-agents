<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * Where the shown values come from, inside a `ComposedView` tree: one line "Sources: WDR · Antenne
 * Münster" with each source's site icon and link, and for a source with a licence (an image from Wikimedia Commons)
 * one attribution line "author · licence · source". In a compact slot (a table cell, inline in a `Text`)
 * it is one chip "WDR +2" that unfolds the full list. Every source needs a `name`; a `url` / `licenseUrl` that is
 * not http(s) is shown as text without a link.
 */
#[ComposedViewNodeProp(name: 'sources', types: [ComposedViewNodeProp::TYPE_ARRAY], isRequired: true, description: 'the sources, each {"name", "url"?, "title"?, "author"?, "license"?, "licenseUrl"?} — name = the publisher or site')]
class SourceRefComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'SourceRef';

    public const bool NAMES_THE_SOURCES = true;

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'the source line of the shown values: each source with icon and link, licence and author when given';
}
