<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * A titled surface that groups nodes (ChatGPT's `Card` with `Card.Header | Body | Footer`).
 * Named `Surface`, not `Card`: the module's noun for a chat element is widget. The
 * outermost surface is raised on the chat (the raised surface of the widget design), a surface inside it is a flat
 * tile, so there are never three background levels. Slots: `media` (one image or video flush at the top), `header`
 * (controls and badges at the end of the title row — period tabs, a "Methodik" popover),
 * `children` (the body) and `footer` (a caption line or small actions at the foot).
 */
#[ComposedViewNodeSlot(name: 'media', acceptsCategories: [ComposedViewNodeCategory::MEDIA], maxChildren: 1, sizeClass: ComposedViewNodeSlot::SIZE_CLASS_FULL, description: 'one image or video flush with the top of the surface')]
#[ComposedViewNodeSlot(name: 'header', acceptsCategories: [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::INPUT], maxChildren: 3, sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT, description: 'controls and badges at the end of the title row (tabs, a badge, a popover)')]
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: ComposedViewNodeCategory::ALL, description: 'the body, one node under the other')]
#[ComposedViewNodeSlot(name: 'footer', acceptsCategories: [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::BLOCK, ComposedViewNodeCategory::INPUT], sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT, description: 'a caption line or small actions at the foot')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], description: 'the title of the surface, in the owner\'s language')]
#[ComposedViewNodeProp(name: 'subtitle', types: [ComposedViewNodeProp::TYPE_STRING], description: 'one muted line under the title (what the numbers are, the period)')]
#[ComposedViewNodeProp(name: 'density', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['regular', 'compact'], description: 'regular (default) or compact (smaller padding, for tiles in a grid or carousel)')]
class SurfaceComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Surface';

    public const array CATEGORIES = [ComposedViewNodeCategory::LAYOUT];

    public const string SUMMARY = 'a titled surface grouping nodes, with optional media, header controls, body and footer';
}
