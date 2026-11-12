<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * Makes its content one clickable area (ChatGPT's `Pressable`): a tile that selects a channel, a row
 * that opens an entity. `onClick` is `{"set": "<state>"}` (writes `value` into that state) or `{"action": "<name>"}`
 * (one of the tree's actions — openEntity, openUrl, copy, resetState); `isSelected` (usually a computed comparison)
 * draws the selection ring. No buttons or inputs inside — one control, one click.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::BLOCK, ComposedViewNodeCategory::DATA, ComposedViewNodeCategory::MEDIA, ComposedViewNodeCategory::LAYOUT], minChildren: 1, description: 'what the clickable area shows (no inputs, no other pressable)')]
#[ComposedViewNodeProp(name: 'onClick', types: [ComposedViewNodeProp::TYPE_EVENT], isRequired: true, description: '{"set": "<state name>"} (writes `value`) or {"action": "<action name>"}')]
#[ComposedViewNodeProp(name: 'value', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER, ComposedViewNodeProp::TYPE_BOOLEAN], description: 'the value a {"set": …} click writes into the state')]
#[ComposedViewNodeProp(name: 'isSelected', types: [ComposedViewNodeProp::TYPE_BOOLEAN], description: 'draw the selection ring (bind a computed comparison, e.g. channel == "Organic")')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'what the click does, for screen readers (e.g. "Show the organic channel")')]
class PressableComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Pressable';

    /** An input, so it never sits directly in another pressable (its slot takes no inputs). */
    public const array CATEGORIES = [ComposedViewNodeCategory::INPUT];

    public const string SUMMARY = 'makes its content one clickable area that sets a state value or runs an action';
}
