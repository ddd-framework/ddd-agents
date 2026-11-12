<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * Its children once per item of `items`, with `{"$item":"field"}` bound to the item — the
 * table rows of a dataset, the list items, the tiles of a grid. Renders no box of its own: the nodes it repeats sit
 * where the `Repeat` sits (in a `Table` they must be `TableRow`s, in a `List` `ListItem`s). At most `maxRepeatRows`
 * items render (the renderer enforces it and reports the rest).
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: ComposedViewNodeCategory::ALL_BUT_HEAVY, acceptsNodeTypes: [TableRowComposedViewNode::NODE_TYPE, ListItemComposedViewNode::NODE_TYPE], minChildren: 1, description: 'the nodes rendered once per item')]
#[ComposedViewNodeProp(name: 'items', types: [ComposedViewNodeProp::TYPE_ARRAY], isRequired: true, description: 'the list to repeat over, usually a binding ({"$data":…}, {"$computed":…})')]
#[ComposedViewNodeProp(name: 'limit', types: [ComposedViewNodeProp::TYPE_NUMBER], isBindable: false, description: 'show the first N items only')]
class RepeatComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Repeat';

    public const array INLINE_ROWS_PROP_NAMES = ['items'];

    public const array CATEGORIES = [ComposedViewNodeCategory::DATA];

    public const bool PROVIDES_ITEM_SCOPE = true;

    public const string SUMMARY = 'its children once per item, $item bound to the item (table rows, list items, tiles)';
}
