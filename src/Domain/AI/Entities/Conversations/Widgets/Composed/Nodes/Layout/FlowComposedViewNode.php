<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * Small items that flow like words and wrap onto as many lines as they need (ChatGPT's `Flow` /
 * `FlowItem`): badges, labels, chips, small buttons, thumbnails. Every item keeps its own width; the slot is compact,
 * so a chart in it renders as a sparkline and an image as a thumbnail. No heavy nodes.
 */
#[ComposedViewNodeSlot(name: ComposedViewNodeSlot::DEFAULT_SLOT_NAME, acceptsCategories: [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::BLOCK, ComposedViewNodeCategory::DATA, ComposedViewNodeCategory::MEDIA, ComposedViewNodeCategory::INPUT], sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT, description: 'the items, each at its own width')]
#[ComposedViewNodeProp(name: 'gap', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewLayoutSpacing::GAPS, description: 'the space between the items: none, xsmall, small (default), medium or large')]
#[ComposedViewNodeProp(name: 'align', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['start', 'center', 'end'], description: 'where the items of a line sit vertically: start, center (default) or end')]
class FlowComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Flow';

    public const array CATEGORIES = [ComposedViewNodeCategory::LAYOUT];

    public const string SUMMARY = 'small items (badges, labels, chips) that flow like words and wrap onto more lines';
}
