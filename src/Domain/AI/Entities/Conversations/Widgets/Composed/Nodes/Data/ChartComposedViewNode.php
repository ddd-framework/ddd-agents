<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A chart over rows (ApexCharts): `data` is the list of rows,
 * `x` the field of the category / time axis, `series` the plotted fields. `{"type":"Chart","kind":"line",
 * "data":{"$data":"months"},"x":"month","series":[{"field":"csu","label":"CSU"},{"field":"afd","label":"AfD"}],
 * "valueFormat":"percent"}`. Kinds: `line`, `area`, `bar` (`horizontal` for ranked bars, `stacked`), `pie` / `donut`
 * (shares of a whole: the first series over the `x` names), `scatter` (`x` numeric), `heatmap` (one heat row per data
 * row named by `x`, one column per series: weekdays × hours), `funnel` (the stages of a process in their order, the
 * first series as centered bars with each stage's conversion from the one before: invitations sent → link opened
 * → booking made) and `radar` (3–8 axes, one per data row named by `x`, one or two series: a profile across its
 * dimensions against the average of a comparison group). A series may hide itself by a binding
 * (`"visible":{"$state":"benchmark"}`). In a compact slot (a table cell) the chart renders as a sparkline without axes
 * and legend.
 *
 * A measurement against an earlier one (a poll against the last election, this month against last month)
 * is ONE bar chart with the change per bar: `deltaField` names the row field of the change, which is written
 * signed at the right of each bar and coloured by its sign (plus green, minus red, zero muted); `deltaUnit` says what
 * the change counts (`pp` percentage points — the figure alone, as the ref writes it — or `%`), `deltaLabel` is the
 * line under the bars ("Right: change in percentage points since January 2026"). Such a chart draws one bar per
 * category from its first series, horizontally. `{"type":"Chart","kind":"bar","horizontal":true,"data":[{"party":
 * "CSU","share":36,"change":-3}],"x":"party","series":[{"field":"share","label":"Share"}],"valueFormat":"percent",
 * "deltaField":"change","deltaUnit":"pp","deltaLabel":"Right: change in percentage points since January 2026",
 * "caption":"Infratest dimap / BR24, 20 Oct 2026 · 1,207 respondents","source":{"name":"infratest-dimap.de","url":…}}`.
 */
#[ComposedViewNodeProp(name: 'kind', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, isBindable: false, allowedValues: ChartComposedViewNode::KINDS, description: 'line, area, bar, pie, donut, scatter, heatmap, funnel or radar')]
#[ComposedViewNodeProp(name: 'data', types: [ComposedViewNodeProp::TYPE_ARRAY], isRequired: true, description: 'the rows (objects), usually a binding: {"$data":…}, {"$computed":…}, {"$from":…}')]
#[ComposedViewNodeProp(name: 'x', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, isBindable: false, description: 'the row field of the category / time axis (the slice name of a pie, the row of a heatmap, the stage of a funnel, the axis of a radar)')]
#[ComposedViewNodeProp(name: 'series', types: [ComposedViewNodeProp::TYPE_ARRAY], isRequired: true, isBindable: false, description: 'the plotted fields: [{"field","label","visible"?}] — one for a pie or a funnel, one column each for a heatmap')]
#[ComposedViewNodeProp(name: 'horizontal', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'bar only: horizontal bars, for a ranking or a poll')]
#[ComposedViewNodeProp(name: 'stacked', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'bar and area only: the series stacked')]
#[ComposedViewNodeProp(name: 'valueFormat', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewDataValueFormat::FORMATS, description: 'how the values are shown on the axis and in the tooltip (default number)')]
#[ComposedViewNodeProp(name: 'currency', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, description: 'the ISO currency code for valueFormat currency (default EUR)')]
#[ComposedViewNodeProp(name: 'min', types: [ComposedViewNodeProp::TYPE_NUMBER], isBindable: false, description: 'the lowest value of the value axis (default: from the data)')]
#[ComposedViewNodeProp(name: 'max', types: [ComposedViewNodeProp::TYPE_NUMBER], isBindable: false, description: 'the highest value of the value axis (default: from the data)')]
#[ComposedViewNodeProp(name: 'xLabel', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the title of the category axis')]
#[ComposedViewNodeProp(name: 'yLabel', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the title of the value axis')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a heading above the chart')]
#[ComposedViewNodeProp(name: 'caption', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a muted line under the chart (the period, what the values mean)')]
#[ComposedViewNodeProp(name: 'source', types: [ComposedViewNodeProp::TYPE_OBJECT], description: 'where the values come from ({"name","url"}), shown as the source line')]
#[ComposedViewNodeProp(name: 'deltaField', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, description: 'bar only: the row field of the change against an earlier measurement, shown signed at the right of each bar (plus green, minus red)')]
#[ComposedViewNodeProp(name: 'deltaUnit', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ChartComposedViewNode::DELTA_UNITS, description: 'what the change counts: pp (percentage points, the figure alone) or % (default: the figure alone)')]
#[ComposedViewNodeProp(name: 'deltaLabel', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the line under the bars that says what the change compares with ("Right: change in percentage points since January 2026")')]
class ChartComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Chart';

    public const array CATEGORIES = [ComposedViewNodeCategory::DATA];

    public const string SUMMARY = 'a line, area, bar, pie, donut, scatter, heatmap, funnel or radar chart over rows; bars with a change per bar; a sparkline in a compact slot';

    /** @var string[] the chart kinds */
    public const array KINDS = ['line', 'area', 'bar', 'pie', 'donut', 'scatter', 'heatmap', 'funnel', 'radar'];

    public const string DELTA_UNIT_PERCENTAGE_POINTS = 'pp';
    public const string DELTA_UNIT_PERCENT = '%';

    /** @var string[] what a per-bar change counts */
    public const array DELTA_UNITS = [self::DELTA_UNIT_PERCENTAGE_POINTS, self::DELTA_UNIT_PERCENT];

    public const array INLINE_ROWS_PROP_NAMES = ['data'];

    public const bool NEEDS_A_SOURCE_FOR_INLINE_FIGURES = true;
}
