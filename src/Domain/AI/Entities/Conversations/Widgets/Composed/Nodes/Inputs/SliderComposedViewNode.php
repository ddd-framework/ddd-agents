<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A slider (ChatGPT's `Slider` in the revenue and MRR simulators): picks a number between
 * `min` and `max` in `step`s and shows it formatted beside its label ("Visitors / month · 35,000", "Conversion rate
 * · 3.0 %"). Writes the number into the tree's local state (`onChange: {"set": …}`) while it is dragged, so computed
 * KPIs follow; nothing reaches the chat or a tool. Usable only on a live (interactive) message, frozen in a replay and
 * in the e-mail capture. Component: `components/inputs/Slider.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'min', types: [ComposedViewNodeProp::TYPE_NUMBER], isRequired: true, description: 'the smallest value')]
#[ComposedViewNodeProp(name: 'max', types: [ComposedViewNodeProp::TYPE_NUMBER], isRequired: true, description: 'the largest value')]
#[ComposedViewNodeProp(name: 'step', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'the step between two values (default 1)')]
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'the current value, usually {"$state": "<name>"}')]
#[ComposedViewNodeProp(name: 'onChange', types: [ComposedViewNodeProp::TYPE_EVENT], description: '{"set": "<state name>"} — writes the number')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'what the number is, shown left of the value')]
#[ComposedViewNodeProp(name: 'unit', types: [ComposedViewNodeProp::TYPE_STRING], description: 'written after the shown value ("%", "€", "km")')]
#[ComposedViewNodeProp(name: 'decimals', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'decimal places of the shown value (default: as many as the step has)')]
#[ComposedViewNodeProp(name: 'showValue', types: [ComposedViewNodeProp::TYPE_BOOLEAN], description: 'show the formatted value beside the label (default true)')]
class SliderComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Slider';

    public const array CATEGORIES = [ComposedViewNodeCategory::INPUT];

    public const string SUMMARY = 'a slider between min and max that writes the number to a state value and shows it formatted';
}
