<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * One formula typeset from LaTeX: a centred block formula, or an inline one
 * inside a sentence. The frontend typesets it with KaTeX in its safe mode (no `\href`, no `\includegraphics`, no HTML
 * extensions); a formula KaTeX cannot read shows its source and is reported `failed`. Component:
 * `components/rich/Math.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'latex', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the formula in LaTeX math notation, without $ delimiters, e.g. "CR = \\frac{N_{Conversions}}{N_{Visitors}} \\times 100\\%"')]
#[ComposedViewNodeProp(name: 'display', types: [ComposedViewNodeProp::TYPE_STRING], isBindable: false, allowedValues: ['block', 'inline'], description: '"block" (default) centres the formula on its own line, "inline" sets it inside a line of text')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a short heading above a block formula ("Conversion rate"), shown with a LaTeX badge')]
#[ComposedViewNodeProp(name: 'caption', types: [ComposedViewNodeProp::TYPE_STRING], description: 'one muted line under a block formula: what the symbols mean, or a worked example')]
class MathComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Math';

    /** The node's text prop is `latex`. */
    public const string TEXT_CONTENT_PROP_NAME = 'latex';

    public const array CATEGORIES = [ComposedViewNodeCategory::BLOCK, ComposedViewNodeCategory::INLINE];

    public const string SUMMARY = 'a formula typeset from LaTeX, as a centred block or inline in a sentence, with an optional heading and caption';
}
