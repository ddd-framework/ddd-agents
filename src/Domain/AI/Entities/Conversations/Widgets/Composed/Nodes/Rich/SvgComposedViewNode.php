<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A vector graphic the model drew itself (e.g. a radar whose geometry follows
 * the sliders): SVG markup, sanitised by the frontend to the SVG profile of DOMPurify — no scripts, no event
 * attributes, no `foreignObject`, no `style` element, no links and no reference outside the graphic (only `#id`
 * references and `url(#id)` paints stay). The markup may be bound to a computed text, so the drawing follows the
 * tree's state. Removed parts are reported `failed` with what was removed; the rest is drawn. Component:
 * `components/rich/Svg.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'markup', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'one <svg> element with a viewBox, e.g. "<svg viewBox=\'0 0 200 200\'>…</svg>"; bind it to a computed text to make the drawing follow the state')]
#[ComposedViewNodeProp(name: 'label', types: [ComposedViewNodeProp::TYPE_STRING], description: 'what the graphic shows, in one sentence (read out by screen readers)')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a short heading above the graphic')]
#[ComposedViewNodeProp(name: 'caption', types: [ComposedViewNodeProp::TYPE_STRING], description: 'one muted line under the heading')]
class SvgComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Svg';

    public const array CATEGORIES = [ComposedViewNodeCategory::DATA, ComposedViewNodeCategory::MEDIA];

    public const string SUMMARY = 'a sanitised vector graphic from SVG markup (no scripts, no external references); the markup may follow the state through a computed text';
}
