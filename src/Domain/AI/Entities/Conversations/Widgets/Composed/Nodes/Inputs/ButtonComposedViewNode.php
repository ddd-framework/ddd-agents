<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Inputs;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A button (ChatGPT's `Button` — "Copy result", "Reset"): runs one of the tree's own actions
 * (`onClick: {"action": "<name>"}` — copy, resetState, openUrl, openEntity) or writes `value` into a state value
 * (`onClick: {"set": "<name>"}`, e.g. a "next" step). It never sends a chat message and never proposes a next step.
 * Usable only on a live (interactive) message, inert in a replay and in the e-mail capture.
 * Component: `components/inputs/Button.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'what the button does ("Copy result")')]
#[ComposedViewNodeProp(name: 'onClick', types: [ComposedViewNodeProp::TYPE_EVENT], isRequired: true, description: '{"action": "<action name>"} or {"set": "<state name>"} with value')]
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER, ComposedViewNodeProp::TYPE_BOOLEAN], description: 'what a {"set": …} click writes')]
#[ComposedViewNodeProp(name: 'variant', types: [ComposedViewNodeProp::TYPE_STRING], allowedValues: ['primary', 'secondary'], description: 'primary for the one main action (default), secondary beside it')]
#[ComposedViewNodeProp(name: 'icon', types: [ComposedViewNodeProp::TYPE_STRING], allowedValues: ['copy', 'reset', 'link', 'add', 'check', 'next', 'calendar', 'info'], description: 'a glyph before the label (copy, reset, link, add, check, next, calendar, info)')]
#[ComposedViewNodeProp(name: 'fullWidth', types: [ComposedViewNodeProp::TYPE_BOOLEAN], description: 'the button takes the whole width')]
class ButtonComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Button';

    /** The node's text prop is `label`. */
    public const string TEXT_CONTENT_PROP_NAME = 'label';

    public const array CATEGORIES = [ComposedViewNodeCategory::INPUT];

    public const string SUMMARY = 'a button that runs one of the tree\'s actions (copy, resetState, openUrl, openEntity) or writes a value to state';
}
