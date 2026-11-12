<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A multi-line text field (ChatGPT's `Textarea`): a note, a draft sentence the composition
 * counts or shows back. Writes what is typed into the tree's local state (`onChange: {"set": …}`); nothing reaches the
 * chat or a tool. Usable only on a live (interactive) message, frozen in a replay and in the e-mail capture. Component:
 * `components/inputs/Textarea.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the current text, usually {"$state": "<name>"}')]
#[ComposedViewNodeProp(name: 'onChange', types: [ComposedViewNodeProp::TYPE_EVENT], description: '{"set": "<state name>"} — writes what is typed')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a short label above the field')]
#[ComposedViewNodeProp(name: 'placeholder', types: [ComposedViewNodeProp::TYPE_STRING], description: 'shown while the field is empty')]
#[ComposedViewNodeProp(name: 'rows', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'the visible lines (default 3)')]
#[ComposedViewNodeProp(name: 'maxLength', types: [ComposedViewNodeProp::TYPE_NUMBER], description: 'the most characters the field takes, shown as a counter')]
class TextareaComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Textarea';

    public const array CATEGORIES = [ComposedViewNodeCategory::INPUT];

    public const string SUMMARY = 'a multi-line text field that writes what is typed to a state value';
}
