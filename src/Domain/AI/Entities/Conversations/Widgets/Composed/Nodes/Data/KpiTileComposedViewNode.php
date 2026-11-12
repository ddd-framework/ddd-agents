<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * One headline number as a tile: the label, the hero value in the one hero
 * scale of the widgets, its unit, the change since the comparison period with an arrow whose colour says
 * whether the change is good (`higherIsBetter`), and a muted detail line. `{"type":"KpiTile","label":"Visitors",
 * "value":{"$computed":"totalVisits"},"format":"number","delta":4.2,"deltaFormat":"percent","detail":"All channels"}`.
 */
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'what the number is, above it ("Visitors", "MRR month 12")')]
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_NUMBER, ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the number (formatted by `format`) or an already written value ("4,32 %")')]
#[ComposedViewNodeProp(name: 'format', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewDataValueFormat::FORMATS, description: 'how a numeric value is shown (default number)')]
#[ComposedViewNodeProp(name: 'currency', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, description: 'the ISO currency code for format currency (default EUR)')]
#[ComposedViewNodeProp(name: 'decimals', types: [ComposedViewNodeProp::TYPE_NUMBER], isBindable: false, description: 'the fraction digits of a numeric value (default: up to 2)')]
#[ComposedViewNodeProp(name: 'signed', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'a positive value gets a plus sign (a growth rate)')]
#[ComposedViewNodeProp(name: 'unit', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a lower-case unit line under the number ("visits", "per month")')]
#[ComposedViewNodeProp(name: 'delta', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'the change against the comparison period, signed (+5, -0.49)')]
#[ComposedViewNodeProp(name: 'deltaFormat', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: [ComposedViewDataValueFormat::FORMAT_NUMBER, ComposedViewDataValueFormat::FORMAT_PERCENT, ComposedViewDataValueFormat::FORMAT_CURRENCY], description: 'how the change is shown (default number)')]
#[ComposedViewNodeProp(name: 'deltaLabel', types: [ComposedViewNodeProp::TYPE_STRING], description: 'what the change compares with ("vs. September")')]
#[ComposedViewNodeProp(name: 'higherIsBetter', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'false for a number where less is better (a rank, a churn rate) — the arrow colour follows (default true)')]
#[ComposedViewNodeProp(name: 'tone', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewDataValueFormat::TONES, description: 'the judgement colour of the hero value itself (default neutral)')]
#[ComposedViewNodeProp(name: 'detail', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a muted line under the tile ("All channels", "simulated")')]
#[ComposedViewNodeProp(name: 'source', types: [ComposedViewNodeProp::TYPE_OBJECT], description: 'where the number comes from ({"name","url"}), shown as the source line')]
class KpiTileComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'KpiTile';

    public const array INLINE_FIGURE_PROP_NAMES = ['value'];

    public const bool NEEDS_A_SOURCE_FOR_INLINE_FIGURES = true;

    public const array CATEGORIES = [ComposedViewNodeCategory::DATA];

    public const string SUMMARY = 'one headline number as a tile: label, hero value, unit, change with a good/bad arrow, detail line';
}
