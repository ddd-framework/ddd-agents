<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\FlowComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Flow` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class FlowComposedViewNodeTest extends UnitTestCase
{
    public function testAValidFlowIsKept(): void
    {
        $wireView = ["type" => "Flow", "gap" => "xsmall", "align" => "end", "children" => [["type" => "Popover", "label" => "Method", "text" => "Weighted."]]];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => $wireView]);
        self::assertInstanceOf(FlowComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($wireView, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testALayoutNodeDoesNotFlow(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Flow", "children" => [["type" => "Stack"], ["type" => "Divider"]]]]);
        self::assertCount(1, $composedViewTreeReading->view->getNodesOfSlot('children'));
        self::assertSame("Divider", $composedViewTreeReading->view->getNodesOfSlot('children')[0]::NODE_TYPE);
        self::assertSame(["INVALID_NODE"], $composedViewTreeReading->findings->getCodes());
    }
}
