<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTone;

/**
 * A status chip inside a `ComposedView` tree: a short state with a judgement tone ("Majority",
 * "No majority", "Live"), rendered as the design's small status badge (Vanguard `StatusBadge`, icon only for an error).
 */
#[ComposedViewNodeProp(name: 'text', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], isRequired: true, description: 'the status, in the owner\'s language — one or two words')]
#[ComposedViewNodeProp(name: 'tone', types: [ComposedViewNodeProp::TYPE_STRING], allowedValues: ComposedViewTone::ACCEPTED_WORDS, description: ComposedViewTone::DESCRIPTION)]
class BadgeComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Badge';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE];

    public const string SUMMARY = 'a small status chip with a tone (success, warning, danger, info, neutral)';
}
