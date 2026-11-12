<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\StackComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Stack` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class StackComposedViewNodeTest extends UnitTestCase
{
    public function testAValidStackIsKeptWithItsChildren(): void
    {
        $wireView = ["type" => "Stack", "direction" => "row", "gap" => "large", "align" => "center", "justify" => "between", "wrap" => false,
            "children" => [["type" => "Spacer", "grow" => true], ["type" => "Divider", "orientation" => "vertical"]]];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => $wireView]);
        self::assertInstanceOf(StackComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($wireView, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnUnknownGapAndAPixelGapAreDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Stack", "gap" => "12px", "direction" => "diagonal", "children" => [["type" => "Divider"]]]]);
        self::assertSame([], $composedViewTreeReading->view->props);
        self::assertCount(1, $composedViewTreeReading->view->getNodesOfSlot('children'));
        self::assertSame(["INVALID_PROP", "INVALID_PROP"], $composedViewTreeReading->findings->getCodes());
    }

    public function testTheGapIsALiteralNotABinding(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["state" => ["gap" => "large"], "view" => ["type" => "Stack", "gap" => ['$state' => "gap"]]]);
        self::assertArrayNotHasKey("gap", $composedViewTreeReading->view->props);
        self::assertSame(["INVALID_PROP"], $composedViewTreeReading->findings->getCodes());
    }
}
