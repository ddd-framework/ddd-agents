<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A date field with a calendar (ChatGPT's `DatePicker`, the reference date of the analytics
 * studio): picks one day and writes it into the tree's local state as `YYYY-MM-DD` (`onChange: {"set": …}`); nothing
 * reaches the chat or a tool. Usable only on a live (interactive) message, frozen in a replay and in the e-mail
 * capture. Component: `components/inputs/DatePicker.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the picked day as YYYY-MM-DD, usually {"$state": "<name>"}')]
#[ComposedViewNodeProp(name: 'onChange', types: [ComposedViewNodeProp::TYPE_EVENT], description: '{"set": "<state name>"} — writes the day as YYYY-MM-DD')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a short label above the field')]
#[ComposedViewNodeProp(name: 'min', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the earliest day that can be picked, YYYY-MM-DD')]
#[ComposedViewNodeProp(name: 'max', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the latest day that can be picked, YYYY-MM-DD')]
class DatePickerComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'DatePicker';

    public const array CATEGORIES = [ComposedViewNodeCategory::INPUT];

    public const string SUMMARY = 'a date field with a calendar that writes the picked day (YYYY-MM-DD) to a state value';
}
