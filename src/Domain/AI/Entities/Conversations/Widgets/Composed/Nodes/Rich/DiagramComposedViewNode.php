<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeCategory;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;

/**
 * A diagram drawn from Mermaid source: flowcharts, sequence diagrams,
 * timelines, state diagrams. The frontend draws it with Mermaid at `securityLevel: strict` (no click handlers, no HTML
 * labels) and sanitises the drawn SVG again; source Mermaid cannot read shows one muted line and is reported `failed`
 * with Mermaid's parse message. `heavy`: never in a compact slot. Component: `components/rich/Diagram.composed.tsx`.
 */
#[ComposedViewNodeProp(name: 'source', types: [ComposedViewNodeProp::TYPE_STRING], isRequired: true, description: 'the Mermaid source, line breaks as \\n, e.g. "flowchart TD\\n  A[Request] --> B{Widget found?}\\n  B -- Yes --> C[Select widget]"')]
#[ComposedViewNodeProp(name: 'title', types: [ComposedViewNodeProp::TYPE_STRING], description: 'a short heading above the diagram')]
#[ComposedViewNodeProp(name: 'caption', types: [ComposedViewNodeProp::TYPE_STRING], description: 'one muted line under the heading: what the diagram shows')]
class DiagramComposedViewNode extends ComposedViewNode
{
    public const string NODE_TYPE = 'Diagram';

    /** The node's text prop is `source`. */
    public const string TEXT_CONTENT_PROP_NAME = 'source';

    public const array CATEGORIES = [ComposedViewNodeCategory::HEAVY];

    public const string SUMMARY = 'a diagram drawn from Mermaid source (flowchart, sequence, state, timeline), with an optional heading and caption';
}
