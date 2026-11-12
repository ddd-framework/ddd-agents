<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

/**
 * The closed spacing and alignment vocabulary of the `ComposedView` layout nodes: the model names a
 * step, never a pixel value — the frontend maps each step onto one `$x*` spacing token
 * (`components/layout/layout-props.ts`), so every composition keeps the design's spacing. Not a node class: the
 * node registry only takes `*ComposedViewNode` files.
 */
class ComposedViewLayoutSpacing
{
    /** @var string[] the gap steps: none, 4px, 8px, 12px (between rows and tiles, the default), 16px (between sections) */
    public const array GAPS = ['none', 'xsmall', 'small', 'medium', 'large'];

    /** @var string[] cross-axis alignment of a row or column */
    public const array ALIGNMENTS = ['start', 'center', 'end', 'stretch', 'baseline'];

    /** @var string[] main-axis distribution of a row or column */
    public const array JUSTIFICATIONS = ['start', 'center', 'end', 'between'];

    /** @var string[] the height (or width in a row) of a Spacer */
    public const array SPACER_SIZES = ['small', 'medium', 'large'];

    public const string GAP_DESCRIPTION = 'the space between the children: none, xsmall, small, medium (default, between rows and tiles) or large (between sections)';
}
