<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\CarouselComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Carousel` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class CarouselComposedViewNodeTest extends UnitTestCase
{
    public function testACarouselOfStaticSlidesIsKept(): void
    {
        $wireView = ["type" => "Carousel", "visibleItems" => 2, "showArrows" => true, "label" => "Channels",
            "children" => [["type" => "Surface", "title" => "Organic"], ["type" => "Surface", "title" => "Paid"]]];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => $wireView]);
        self::assertInstanceOf(CarouselComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($wireView, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testItemsOpenAnItemScopeForTheTemplate(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            "data" => ["channels" => [["name" => "Organic"], ["name" => "Paid"]]],
            "view" => ["type" => "Carousel", "items" => ['$data' => "channels"], "children" => [["type" => "Surface", "title" => ['$item' => "name"]]]],
        ]);
        self::assertSame(['$item' => "name"], $composedViewTreeReading->view->getNodesOfSlot('children')[0]->props["title"]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnEmptyCarouselIsReportedAndAHeavyNodeIsNoSlide(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Carousel", "children" => [["type" => "Widget", "widget" => "ChannelConnections"]]]]);
        self::assertSame([], $composedViewTreeReading->view->getNodesOfSlot('children'));
        self::assertSame(["INVALID_NODE", "INVALID_SLOT_NODE_COUNT"], array_values(array_unique($composedViewTreeReading->findings->getCodes())), 'the dropped slide, then the carousel left without one');
    }
}
