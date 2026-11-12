<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A drop-down choice (ChatGPT's `Select`): one of a longer list of choices — a channel, a
 * workshop, a category. Writes the picked choice into the tree's local state (`onChange: {"set": …}`); nothing reaches
 * the chat or a tool. Usable only on a live (interactive) message, frozen in a replay and in the e-mail capture.
 * Component: `components/inputs/Select.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'options', types: [ComposedViewNodeProp::TYPE_ARRAY], isRequired: true, description: 'the choices: a list of labels, or of {"id", "label", "description", "disabled"} objects; the picked id (or label) is written to the state')]
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], description: 'the picked choice, usually {"$state": "<name>"}')]
#[ComposedViewNodeProp(name: 'onChange', types: [ComposedViewNodeProp::TYPE_EVENT], description: '{"set": "<state name>"} — writes the picked id')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a short label above the control')]
#[ComposedViewNodeProp(name: 'placeholder', types: [ComposedViewNodeProp::TYPE_STRING], description: 'shown while nothing is picked')]
class SelectComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Select';

    public const array CATEGORIES = [ComposedViewNodeCategory::INPUT];

    public const string OPTIONS_PROP_NAME = 'options';

    public const string SUMMARY = 'a drop-down: one of a longer list of choices, the picked one written to a state value';
}
