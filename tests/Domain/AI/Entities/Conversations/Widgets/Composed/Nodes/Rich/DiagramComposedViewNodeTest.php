<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich\DiagramComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Diagram` node: Mermaid source is kept as written (the frontend checks it), a node without
 * source is dropped, and as a `heavy` node it fits a slot that accepts heavy nodes but never a compact one.
 */
class DiagramComposedViewNodeTest extends UnitTestCase
{
    public function testMermaidSourceIsKeptAsWritten(): void
    {
        $mermaidSource = "flowchart TD\n  A[User request] --> B[Widget search]\n  B --> C{Matching widget?}\n  C -- Yes --> D[Select widget]";
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => [
            'type' => 'Diagram',
            'source' => $mermaidSource,
            'title' => 'Widget life cycle',
        ]]);
        self::assertInstanceOf(DiagramComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($mermaidSource, $composedViewTreeReading->view->props['source']);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testADiagramWithoutSourceIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Diagram', 'source' => 42]]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['INVALID_PROP', 'INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testAHeavyDiagramNeverFitsACompactSlot(): void
    {
        $heavyRegularSlot = new ComposedViewNodeSlot(acceptsCategories: ['block', 'heavy']);
        $heavyCompactSlot = new ComposedViewNodeSlot(acceptsCategories: ['block', 'heavy'], sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT);
        $defaultSlot = new ComposedViewNodeSlot();
        self::assertTrue(DiagramComposedViewNode::isTypeAcceptedBySlot($heavyRegularSlot));
        self::assertFalse(DiagramComposedViewNode::isTypeAcceptedBySlot($heavyCompactSlot));
        self::assertFalse(DiagramComposedViewNode::isTypeAcceptedBySlot($defaultSlot), 'a default slot takes no heavy node');
    }
}
