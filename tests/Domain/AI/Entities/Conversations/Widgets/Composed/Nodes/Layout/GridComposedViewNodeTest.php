<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\GridComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Grid` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class GridComposedViewNodeTest extends UnitTestCase
{
    public function testAValidGridIsKept(): void
    {
        $wireView = ["type" => "Grid", "columns" => 3, "gap" => "small", "minColumnWidth" => "regular",
            "children" => [["type" => "Surface", "title" => "Visitors"], ["type" => "Surface", "title" => "Conversions"], ["type" => "Surface", "title" => "Rate"]]];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => $wireView]);
        self::assertInstanceOf(GridComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($wireView, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testColumnsOutsideOneToFourAreDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Grid", "columns" => 7]]);
        self::assertArrayNotHasKey("columns", $composedViewTreeReading->view->props);
        self::assertSame(["INVALID_PROP"], $composedViewTreeReading->findings->getCodes());
    }

    public function testANamedSlotTheGridDoesNotHaveIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Grid", "slots" => ["items" => [["type" => "Divider"]]]]]);
        self::assertSame([], $composedViewTreeReading->view->getNodesOfSlots());
        self::assertSame(["INVALID_NODE"], $composedViewTreeReading->findings->getCodes());
    }
}
