<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * One cell of a `TableRow` (`Cell.content`): a formatted `value`, or other nodes in its compact
 * slot (an `Icon`, a `Badge`, a sparkline `Chart`, an `Image` thumbnail), or both. `bar` draws a share bar in the cell
 * (a poll), `heat` tints it by intensity (a cohort heatmap). No category — only a `TableRow`
 * takes it.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::BLOCK, ComposedViewNodeCategory::DATA, ComposedViewNodeCategory::MEDIA, ComposedViewNodeCategory::INPUT], sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT, description: 'nodes shown in the cell after the value (compact: a chart is a sparkline, an image a thumbnail)')]
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER, ComposedViewNodeProp::TYPE_BOOLEAN], description: 'the cell text or number, usually {"$item":"field"}')]
#[ComposedViewNodeProp(name: 'format', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewDataValueFormat::FORMATS, description: 'how a numeric value is shown (default number)')]
#[ComposedViewNodeProp(name: 'currency', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, description: 'the ISO currency code for format currency (default EUR)')]
#[ComposedViewNodeProp(name: 'decimals', types: [ComposedViewNodeProp::TYPE_NUMBER], isBindable: false, description: 'the fraction digits of a numeric value (default: up to 2)')]
#[ComposedViewNodeProp(name: 'unit', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, description: 'a unit written after the value')]
#[ComposedViewNodeProp(name: 'align', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewDataValueFormat::ALIGNS, description: 'start, center or end (numbers: end)')]
#[ComposedViewNodeProp(name: 'tone', types: [ComposedViewNodeProp::TYPE_STRING], allowedValues: ComposedViewDataValueFormat::TONES, description: 'the judgement colour of the value (success: a +2 in green, danger: a -3 in red)')]
#[ComposedViewNodeProp(name: 'signed', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'a positive number gets a plus sign (a change column)')]
#[ComposedViewNodeProp(name: 'bar', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'a share bar in the cell, the filled part from 0 to 1')]
#[ComposedViewNodeProp(name: 'heat', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'tints the cell by intensity from 0 to 1 (a heatmap cell); no value = an empty cell')]
class TableCellComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'TableCell';

    public const array INLINE_FIGURE_PROP_NAMES = ['value'];

    /** The node's text prop is `value`. */
    public const string TEXT_CONTENT_PROP_NAME = 'value';

    public const array CATEGORIES = [];

    public const string SUMMARY = 'one table cell: a formatted value, a share bar or heat tint, and compact nodes (icon, badge, sparkline)';
}
