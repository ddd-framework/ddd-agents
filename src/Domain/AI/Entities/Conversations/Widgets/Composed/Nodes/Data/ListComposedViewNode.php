<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * A list of entries divided by hairlines (`block`). The short form: `items` of strings or of
 * `{"title","description"?,"meta"?,"value"?}`. The node form: `ListItem` children and `Repeat`s of `ListItem`s, which
 * hold other nodes. `marker` puts a bullet, a number or a check before each entry.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: [], acceptsNodeTypes: [ListItemComposedViewNode::NODE_TYPE, RepeatComposedViewNode::NODE_TYPE], description: 'ListItem nodes and Repeats of ListItems')]
#[ComposedViewNodeProp(name: 'items', types: [ComposedViewNodeProp::TYPE_ARRAY], description: 'the short form: strings, or {"title","description"?,"meta"?,"value"?}')]
#[ComposedViewNodeProp(name: 'marker', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ListComposedViewNode::MARKERS, description: 'bullet, number, check or none (default none)')]
#[ComposedViewNodeProp(name: 'emptyText', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the line shown when the list has no entries')]
class ListComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'List';

    public const array CATEGORIES = [ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'a hairline-divided list: items (strings or title/description/meta/value) or ListItem children';

    /** @var string[] */
    public const array MARKERS = ['none', 'bullet', 'number', 'check'];
}
