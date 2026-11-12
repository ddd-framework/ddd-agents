<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * A horizontal row of slides the owner pages through (ChatGPT's `Carousel` + `CarouselItem`, e.g. a
 * "Kanal-Karussell"): `visibleItems` slides at once with the next one peeking in, previous / next
 * buttons and swipe. Two ways to fill it: every child node is one slide, or — with `items` (a list, usually
 * `{"$data":…}` or `{"$computed":…}`) — the children are a template rendered once per item with `{"$item":"field"}`
 * bound to that item (the node opens an item scope, like `Repeat`), capped at the tree's `maxRepeatRows`.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: ComposedViewNodeCategory::ALL_BUT_HEAVY, minChildren: 1, description: 'the slides (one node each) or, with `items`, the template of one slide')]
#[ComposedViewNodeProp(name: 'items', types: [ComposedViewNodeProp::TYPE_ARRAY], description: 'the list the children repeat over, one slide per item ($item binds a field of the item)')]
#[ComposedViewNodeProp(name: 'visibleItems', types: [ComposedViewNodeProp::TYPE_NUMBER], isBindable: false, allowedValues: [1, 2, 3, 4], description: 'how many slides are fully visible at once (1 to 4, default 2); the next one peeks in')]
#[ComposedViewNodeProp(name: 'showArrows', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'show the previous / next buttons (default true)')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'what the slides are, for screen readers (e.g. "Visitors per channel")')]
class CarouselComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Carousel';

    public const array CATEGORIES = [ComposedViewNodeCategory::LAYOUT];

    public const bool PROVIDES_ITEM_SCOPE = true;

    public const string SUMMARY = 'slides side by side with previous / next buttons; each child is a slide, or the children repeat per item of `items`';
}
