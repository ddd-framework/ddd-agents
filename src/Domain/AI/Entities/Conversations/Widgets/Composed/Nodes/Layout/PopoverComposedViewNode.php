<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * A small button that opens a floating explanation (ChatGPT's `Popover` with `PopoverTrigger` /
 * `PopoverContent`, e.g. an "ⓘ Methodik"): `label` (with an info or help icon) is the button, the
 * explanation is `text` or the nodes of `content`. A click opens it, a second click, Escape or a click elsewhere closes
 * it. For a note the owner may want, not for anything they must read — the e-mail shows the button only.
 */
#[ComposedViewNodeSlot(name: 'content', acceptsCategories: [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::BLOCK, ComposedViewNodeCategory::DATA, ComposedViewNodeCategory::MEDIA], sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT, description: 'the explanation, when it is more than one plain text')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the button text (e.g. "Method")')]
#[ComposedViewNodeProp(name: 'text', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the explanation as plain text')]
#[ComposedViewNodeProp(name: 'icon', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['info', 'help', 'none'], description: 'the icon before the label: info (default), help or none')]
#[ComposedViewNodeProp(name: 'placement', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['start', 'end'], description: 'which edge of the button the panel lines up with: start or end (default — for a button at the right of a title row)')]
class PopoverComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Popover';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE];

    public const string SUMMARY = 'a small button (label + info icon) that opens a floating explanation on click';
}
