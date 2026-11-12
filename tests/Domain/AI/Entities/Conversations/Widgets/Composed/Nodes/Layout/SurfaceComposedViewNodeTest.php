<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\SurfaceComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Surface` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class SurfaceComposedViewNodeTest extends UnitTestCase
{
    public function testAValidSurfaceKeepsItsSlots(): void
    {
        $wireView = ["type" => "Surface", "title" => "Analytics", "subtitle" => "Demo data", "density" => "compact",
            "children" => [["type" => "Divider"]],
            "slots" => ["header" => [["type" => "Popover", "label" => "Method", "text" => "Weighted by channel."]], "footer" => [["type" => "Quote", "text" => "Great."]]]];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => $wireView]);
        self::assertInstanceOf(SurfaceComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($wireView, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testTheTitleBindsState(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["state" => ["period" => "7 days"], "view" => ["type" => "Surface", "title" => ['$state' => "period"]]]);
        self::assertSame(['$state' => "period"], $composedViewTreeReading->view->props["title"]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testABlockInTheHeaderIsDroppedAndTheBodyStays(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Surface", "children" => [["type" => "Spacer"]], "slots" => ["header" => [["type" => "Divider"]]]]]);
        self::assertSame([], $composedViewTreeReading->view->getNodesOfSlot('header'));
        self::assertCount(1, $composedViewTreeReading->view->getNodesOfSlot('children'));
        self::assertSame(["INVALID_NODE"], $composedViewTreeReading->findings->getCodes());
    }

    public function testTheMediaSlotHoldsOneNodeOnly(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Surface", "slots" => ["media" => [["type" => "Video", "title" => "A"], ["type" => "Video", "title" => "B"]]]]]);
        self::assertCount(1, $composedViewTreeReading->view->getNodesOfSlot('media'));
        self::assertSame(["INVALID_NODE"], $composedViewTreeReading->findings->getCodes());
    }
}
