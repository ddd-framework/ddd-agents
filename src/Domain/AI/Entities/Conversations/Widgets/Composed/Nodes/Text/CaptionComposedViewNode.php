<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * Secondary text inside a `ComposedView` tree: the sub-line under a title, a unit, a date, a method note
 * ("as of 19 October 2026", "derived from two sources") — muted and small (`textCaption` in `--fn-fg-light`).
 */
#[ComposedViewNodeProp(name: 'text', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], isRequired: true, description: 'the secondary text, in the owner\'s language')]
class CaptionComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Caption';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'muted secondary text: a sub-line, a unit, a date, a method note';
}
