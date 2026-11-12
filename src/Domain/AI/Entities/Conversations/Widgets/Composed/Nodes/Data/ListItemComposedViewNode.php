<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * One entry of a `List`: a title over a description and a muted meta line, a value at the end,
 * and other nodes in its slot (a `Badge`, a `Link`, a small `Chart`). No category — only a `List` or a `Repeat` takes it.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: ComposedViewNodeCategory::ALL_BUT_HEAVY, description: 'nodes shown under the title')]
#[ComposedViewNodeSlot(name: 'trailing', acceptsCategories: [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::INPUT, ComposedViewNodeCategory::DATA], maxChildren: 2, sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT, description: 'at the end of the entry: a badge, an icon, a sparkline')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], description: 'the entry, bold')]
#[ComposedViewNodeProp(name: 'description', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a line under the title')]
#[ComposedViewNodeProp(name: 'meta', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a muted line (a date, a source)')]
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], description: 'a figure at the end of the entry')]
class ListItemComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'ListItem';

    public const array CATEGORIES = [];

    public const string SUMMARY = 'one List entry: title, description, meta, value, plus nodes under it and at its end';
}
