<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A short label inside a `ComposedView` tree: the name above a value, an input or a group
 * ("PERIOD", "CHANNEL") — the design's section label (muted, medium, uppercase, letter-spaced, `WidgetLabel`).
 */
#[ComposedViewNodeProp(name: 'text', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], isRequired: true, description: 'the label, in the owner\'s language — a few words')]
class LabelComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Label';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE];

    public const string SUMMARY = 'a short label above a value, an input or a group';
}
