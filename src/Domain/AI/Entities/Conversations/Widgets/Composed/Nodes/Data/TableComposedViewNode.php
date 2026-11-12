<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * A table. Two ways to fill it, combinable:
 *
 * - the short form — `columns` + `rows`: every cell is the row's field `key`, formatted by the column
 *   (`{"type":"Table","columns":[{"key":"coalition","label":"Coalition"},{"key":"seats","label":"Seats",
 *   "align":"end"}],"rows":{"$data":"coalitions"}}`);
 * - the node form — `children` of `TableRow`s (a `header` row first) and `Repeat`s of `TableRow`s, whose `TableCell`s
 *   hold other nodes (an `Icon`, a `Badge`, a sparkline `Chart`).
 *
 * Rows of the node form follow the rows of the short form (a total row under the data rows). The `source` is one
 * `{"name","url"}` or a list of them — one entry per page the values come from (two products compared from two pages).
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: [], acceptsNodeTypes: [TableRowComposedViewNode::NODE_TYPE, RepeatComposedViewNode::NODE_TYPE], description: 'TableRow nodes and Repeats of TableRows, in display order')]
#[ComposedViewNodeProp(name: 'columns', types: [ComposedViewNodeProp::TYPE_ARRAY], isBindable: false, description: 'the short form: [{"key","label","align"?,"format"?,"decimals"?,"currency"?,"unit"?}] — the header row and how each cell is shown')]
#[ComposedViewNodeProp(name: 'rows', types: [ComposedViewNodeProp::TYPE_ARRAY], description: 'the short form: the rows (objects with the column keys), usually a binding')]
#[ComposedViewNodeProp(name: 'caption', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a muted line under the table (what the figures are, the date)')]
#[ComposedViewNodeProp(name: 'emptyText', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the line shown when the short form has no rows')]
#[ComposedViewNodeProp(name: 'source', types: [ComposedViewNodeProp::TYPE_OBJECT, ComposedViewNodeProp::TYPE_ARRAY], description: 'where the figures come from ({"name","url"}, or a list of them when the values come from several pages), shown as the source line')]
class TableComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Table';

    public const array INLINE_ROWS_PROP_NAMES = ['rows'];

    public const bool NEEDS_A_SOURCE_FOR_INLINE_FIGURES = true;

    public const array CATEGORIES = [ComposedViewNodeCategory::DATA];

    public const string SUMMARY = 'a table: columns + rows, or TableRow / Repeat children whose TableCells hold other nodes';
}
