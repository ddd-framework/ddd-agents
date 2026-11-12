<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * One checkbox (ChatGPT's `Checkbox`): switches one option on or off — "show the comparison
 * values". Writes `true` / `false` into the tree's local state (`onChange: {"set": …}`); nothing reaches the chat or a
 * tool. Usable only on a live (interactive) message, frozen in a replay and in the e-mail capture. Component:
 * `components/inputs/Checkbox.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'what the box switches on')]
#[ComposedViewNodeProp(name: 'checked', types: [ComposedViewNodeProp::TYPE_BOOLEAN], description: 'whether it is ticked, usually {"$state": "<name>"}')]
#[ComposedViewNodeProp(name: 'onChange', types: [ComposedViewNodeProp::TYPE_EVENT], description: '{"set": "<state name>"} — writes true or false')]
#[ComposedViewNodeProp(name: 'description', types: [ComposedViewNodeProp::TYPE_STRING], description: 'one muted line under the label')]
class CheckboxComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Checkbox';

    /** The node's text prop is `label`. */
    public const string TEXT_CONTENT_PROP_NAME = 'label';

    public const array CATEGORIES = [ComposedViewNodeCategory::INPUT];

    public const string SUMMARY = 'one checkbox that writes true or false to a state value';
}
