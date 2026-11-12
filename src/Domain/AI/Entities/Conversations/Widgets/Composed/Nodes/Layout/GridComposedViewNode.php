<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * Equal columns (ChatGPT's `Grid columns="2|3"` with `GridItem`s): each
 * child is one cell, filled row by row. `columns` is the most columns the grid shows; on a narrow pane a cell never
 * gets narrower than its minimum width (`minColumnWidth`), so the grid drops to fewer columns instead of squeezing —
 * three KPI tiles become two, then one, on a phone. A cell's size class follows `columns`: one
 * column gives full cells, two regular ones, three or four compact ones — a compact embedded widget (a snapshot tile)
 * fits a 4-column grid, a regular one (a profile status) needs at most two; a map stays allowed in a compact cell.
 */
#[ComposedViewNodeSlot(
    name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME,
    acceptsCategories: ComposedViewNodeCategory::ALL,
    description: 'the cells, one node each, filled row by row; 1 column = full cells, 2 = regular, 3–4 = compact',
    sizeClassFromProp: 'columns',
    sizeClassesByPropValue: [1 => ComposedViewNodeSlot::SIZE_CLASS_FULL, 2 => ComposedViewNodeSlot::SIZE_CLASS_REGULAR, 3 => ComposedViewNodeSlot::SIZE_CLASS_COMPACT, 4 => ComposedViewNodeSlot::SIZE_CLASS_COMPACT],
    acceptsHeavyWhenCompact: true,
)]
#[ComposedViewNodeProp(name: 'columns', types: [ComposedViewNodeProp::TYPE_NUMBER], isBindable: false, allowedValues: [1, 2, 3, 4], description: 'the most columns side by side (1 to 4, default 2)')]
#[ComposedViewNodeProp(name: 'gap', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewLayoutSpacing::GAPS, description: ComposedViewLayoutSpacing::GAP_DESCRIPTION)]
#[ComposedViewNodeProp(name: 'minColumnWidth', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['compact', 'regular'], description: 'the narrowest a cell may get before the grid shows fewer columns: compact (KPI tiles) or regular (charts, images, text tiles); default compact when every cell is a KpiTile, Pressable, Badge, Icon, Favicon or Button, else regular')]
class GridComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Grid';

    public const array CATEGORIES = [ComposedViewNodeCategory::LAYOUT];

    public const string SUMMARY = 'equal columns (1 to 4), one child per cell; drops to fewer columns on a narrow screen';
}
