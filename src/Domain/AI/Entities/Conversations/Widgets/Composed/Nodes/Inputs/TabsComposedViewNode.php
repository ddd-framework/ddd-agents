<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A segmented control (ChatGPT's `SegmentedControl`): two to a handful of short choices side by
 * side, one picked — "7 days | 30 days", "Bear | Base | Bull". It writes the picked choice into the tree's local state
 * (`onChange: {"set": "<state name>"}`), so every node bound to that state follows; nothing reaches the chat or a
 * tool. Usable only on a live (interactive) message, frozen in a replay and in the e-mail capture. Component:
 * `components/inputs/Tabs.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'options', types: [ComposedViewNodeProp::TYPE_ARRAY], isRequired: true, description: 'the choices: a list of labels, or of {"id", "label", "description", "disabled"} objects; the picked id (or label) is written to the state')]
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], description: 'the picked choice, usually {"$state": "<name>"}')]
#[ComposedViewNodeProp(name: 'onChange', types: [ComposedViewNodeProp::TYPE_EVENT], description: '{"set": "<state name>"} — writes the picked id')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a short label above the control')]
class TabsComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Tabs';

    public const array CATEGORIES = [ComposedViewNodeCategory::INPUT];

    public const string OPTIONS_PROP_NAME = 'options';

    public const string SUMMARY = 'a segmented control: a few short choices side by side, the picked one written to a state value';
}
