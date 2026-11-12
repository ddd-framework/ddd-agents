<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\QuoteComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Quote` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class QuoteComposedViewNodeTest extends UnitTestCase
{
    public function testAValidQuoteIsKept(): void
    {
        $wireView = ["type" => "Quote", "text" => "Best coffee in town.", "attribution" => "Anna K.", "source" => ["name" => "Google", "url" => "https://maps.google.com"]];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => $wireView]);
        self::assertInstanceOf(QuoteComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($wireView, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testASourceThatIsNoObjectIsDroppedAndTheQuoteStays(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Quote", "text" => "Great.", "source" => "Google"]]);
        self::assertSame(["text" => "Great."], $composedViewTreeReading->view->props);
        self::assertSame(["INVALID_PROP"], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnInputIsNoQuotedContent(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["state" => ["picked" => ""], "view" => ["type" => "Quote", "children" => [["type" => "Pressable", "onClick" => ["set" => "picked"], "children" => [["type" => "Divider"]]]]]]);
        self::assertSame([], $composedViewTreeReading->view->getNodesOfSlot('children'));
        self::assertSame(["INVALID_NODE"], $composedViewTreeReading->findings->getCodes());
    }
}
