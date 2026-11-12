<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * A row or a column of nodes (ChatGPT's `Row` / `Column` / `Box`): the children
 * in order, with one gap step between them. A row wraps onto the next line when the chat pane is too narrow (`wrap`,
 * default true), so nothing is cut on a phone. Any node may sit in it, maps and embedded widgets included.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: ComposedViewNodeCategory::ALL, description: 'the nodes of the row or column, in order')]
#[ComposedViewNodeProp(name: 'direction', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['row', 'column'], description: 'row (side by side) or column (one under the other, the default)')]
#[ComposedViewNodeProp(name: 'gap', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewLayoutSpacing::GAPS, description: ComposedViewLayoutSpacing::GAP_DESCRIPTION)]
#[ComposedViewNodeProp(name: 'align', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewLayoutSpacing::ALIGNMENTS, description: 'cross-axis alignment: start, center, end, stretch (the column default) or baseline')]
#[ComposedViewNodeProp(name: 'justify', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewLayoutSpacing::JUSTIFICATIONS, description: 'main-axis distribution: start (default), center, end or between (first and last at the edges, e.g. a title left and a control right)')]
#[ComposedViewNodeProp(name: 'wrap', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'a row continues on the next line when it does not fit (default true)')]
class StackComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Stack';

    public const array CATEGORIES = [ComposedViewNodeCategory::LAYOUT];

    public const string SUMMARY = 'a row or a column of nodes with one gap step between them; rows wrap on a narrow screen';
}
