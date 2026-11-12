<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * One row of a `Table` (`Row.cells`): its `TableCell`s in column order. `header` makes it the
 * column header row, `emphasis` a total row. No category — only a `Table` or a `Repeat` (which names it) takes it.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: [], acceptsNodeTypes: [TableCellComposedViewNode::NODE_TYPE], minChildren: 1, description: 'the TableCell nodes, one per column')]
#[ComposedViewNodeProp(name: 'header', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'the column header row')]
#[ComposedViewNodeProp(name: 'emphasis', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'a total or summary row, shown bold')]
class TableRowComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'TableRow';

    public const array CATEGORIES = [];

    public const string SUMMARY = 'one table row of TableCells; header for the column titles, emphasis for a total row';
}
