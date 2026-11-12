<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\DividerComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Divider` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class DividerComposedViewNodeTest extends UnitTestCase
{
    public function testAValidDividerIsKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Divider", "orientation" => "vertical"]]);
        self::assertInstanceOf(DividerComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame(["type" => "Divider", "orientation" => "vertical"], $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testADividerHoldsNoChildren(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Divider", "orientation" => "slanted", "children" => [["type" => "Spacer"]]]]);
        self::assertSame(["type" => "Divider"], $composedViewTreeReading->view->toWireArray());
        self::assertSame(["INVALID_PROP", "INVALID_NODE"], $composedViewTreeReading->findings->getCodes());
    }
}
