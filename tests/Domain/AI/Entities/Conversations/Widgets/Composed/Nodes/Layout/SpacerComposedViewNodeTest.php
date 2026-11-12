<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\SpacerComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Spacer` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class SpacerComposedViewNodeTest extends UnitTestCase
{
    public function testAValidSpacerIsKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Spacer", "size" => "large", "grow" => true]]);
        self::assertInstanceOf(SpacerComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame(["type" => "Spacer", "size" => "large", "grow" => true], $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAPixelSizeAndAStringGrowAreDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Spacer", "size" => 24, "grow" => "yes"]]);
        self::assertSame([], $composedViewTreeReading->view->props);
        self::assertSame(["INVALID_PROP", "INVALID_PROP"], $composedViewTreeReading->findings->getCodes());
    }
}
