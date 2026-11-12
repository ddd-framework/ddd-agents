<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTone;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;

/**
 * A run of plain text inside a `ComposedView` tree: a paragraph on its own, or a run inside another
 * `Text`. Its default slot takes inline nodes (another `Text`, `Badge`, `Link`, `Favicon`, `SourceRef`, …) that
 * follow the text on the same line, so one paragraph can end in its source chip ("Sources: WDR and Antenne Münster.
 * [WDR +2]"). `weight` and `tone` pick from the design's text roles; `maxLines` clamps a long text while the full
 * text stays readable on hover. No markup is parsed (that is `Markdown`).
 */
#[ComposedViewNodeSlot(name: 'children', acceptsCategories: [ComposedViewNodeCategory::INLINE], sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT, description: 'inline nodes that follow the text on the same line')]
#[ComposedViewNodeProp(name: 'text', types: [ComposedViewNodeProp::TYPE_STRING, ComposedViewNodeProp::TYPE_NUMBER], description: 'the text, in the owner\'s language (a Text with only children may leave it out)')]
#[ComposedViewNodeProp(name: 'weight', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['regular', 'medium', 'bold'], description: 'regular (default), medium or bold')]
#[ComposedViewNodeProp(name: 'tone', types: [ComposedViewNodeProp::TYPE_STRING], allowedValues: ComposedViewTone::ACCEPTED_WORDS, description: ComposedViewTone::DESCRIPTION)]
#[ComposedViewNodeProp(name: 'maxLines', types: [ComposedViewNodeProp::TYPE_NUMBER], isBindable: false, description: 'clamp the text at this many lines (1–10); the full text shows on hover')]
class TextComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Text';

    public const array CATEGORIES = [ComposedViewNodeCategory::INLINE, ComposedViewNodeCategory::BLOCK];

    public const string SUMMARY = 'plain text, a paragraph or an inline run; its children are inline nodes on the same line';
}
