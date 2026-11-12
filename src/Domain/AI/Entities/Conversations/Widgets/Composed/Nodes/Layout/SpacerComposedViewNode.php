<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * Empty room (ChatGPT's `Spacer`): a fixed step of extra space, or — with `grow` in a row — all the
 * free room, which pushes the nodes after it to the far end. Holds nothing.
 */
#[ComposedViewNodeProp(name: 'size', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ComposedViewLayoutSpacing::SPACER_SIZES, description: 'the extra space: small, medium (default) or large')]
#[ComposedViewNodeProp(name: 'grow', types: [ComposedViewNodeProp::TYPE_BOOLEAN], isBindable: false, description: 'take all the free room of the row or column, pushing the following nodes to the end')]
class SpacerComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Spacer';

    public const array CATEGORIES = [ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'empty room: a fixed step, or all the free room of a row (grow)';
}
