<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A one-line text or number field (ChatGPT's `Input`): an amount, a name, a URL the
 * composition computes with. Writes what is typed into the tree's local state (`onChange: {"set": …}`; a number field
 * writes a number, or null while empty); nothing reaches the chat or a tool. Usable only on a live (interactive)
 * message, frozen in a replay and in the e-mail capture. Component: `components/inputs/Input.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], description: 'the current text or number, usually {"$state": "<name>"}')]
#[ComposedViewNodeProp(name: 'onChange', types: [ComposedViewNodeProp::TYPE_EVENT], description: '{"set": "<state name>"} — writes what is typed')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a short label above the field')]
#[ComposedViewNodeProp(name: 'placeholder', types: [ComposedViewNodeProp::TYPE_STRING], description: 'shown while the field is empty')]
#[ComposedViewNodeProp(name: 'inputType', types: [ComposedViewNodeProp::TYPE_STRING], allowedValues: ['text', 'number', 'email', 'url'], description: 'what is typed (default text)')]
#[ComposedViewNodeProp(name: 'maxLength', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'the most characters the field takes')]
class InputComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Input';

    public const array CATEGORIES = [ComposedViewNodeCategory::INPUT];

    public const string SUMMARY = 'a one-line text or number field that writes what is typed to a state value';
}
