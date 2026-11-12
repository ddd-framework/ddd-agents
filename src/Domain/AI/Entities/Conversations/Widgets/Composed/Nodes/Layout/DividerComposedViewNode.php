<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A hairline between two parts (ChatGPT's `Divider`):
 * horizontal in a column, vertical between the items of a row. Holds nothing.
 */
#[ComposedViewNodeProp(name: 'orientation', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['horizontal', 'vertical'], description: 'horizontal (default, in a column) or vertical (between the items of a row)')]
class DividerComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Divider';

    public const array CATEGORIES = [ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'a hairline between two parts, horizontal or vertical';
}
