<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\PopoverComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Popover` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class PopoverComposedViewNodeTest extends UnitTestCase
{
    public function testAValidPopoverIsKept(): void
    {
        $wireView = ["type" => "Popover", "label" => "Method", "text" => "Visitors are weighted by channel.", "icon" => "help", "placement" => "start",
            "slots" => ["content" => [["type" => "Quote", "text" => "Benchmark: 90 %"]]]];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => $wireView]);
        self::assertInstanceOf(PopoverComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($wireView, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAPopoverWithoutALabelIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Stack", "children" => [["type" => "Popover", "text" => "orphan"], ["type" => "Divider"]]]]);
        self::assertCount(1, $composedViewTreeReading->view->getNodesOfSlot('children'));
        self::assertSame(["INVALID_NODE"], $composedViewTreeReading->findings->getCodes());
    }

    public function testALayoutNodeIsNoPopoverContent(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Popover", "label" => "Method", "slots" => ["content" => [["type" => "Grid"]]]]]);
        self::assertSame([], $composedViewTreeReading->view->getNodesOfSlot('content'));
        self::assertSame(["INVALID_NODE"], $composedViewTreeReading->findings->getCodes());
    }
}
