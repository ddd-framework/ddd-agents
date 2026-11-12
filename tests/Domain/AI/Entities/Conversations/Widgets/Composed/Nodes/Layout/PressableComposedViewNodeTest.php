<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\PressableComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Pressable` node through the real reader: a valid node is kept as written, an invalid prop or slot
 * child is dropped with its finding code and the rest of the node stays.
 */
class PressableComposedViewNodeTest extends UnitTestCase
{
    public function testAPressableThatSetsStateIsKept(): void
    {
        $wireView = ["type" => "Pressable", "onClick" => ["set" => "channel"], "value" => "Organic", "isSelected" => ['$computed' => "isOrganic"], "label" => "Show organic",
            "children" => [["type" => "Surface", "title" => "Organic"]]];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["state" => ["channel" => "all"], "computed" => ["isOrganic" => "channel == \"Organic\""], "view" => $wireView]);
        self::assertInstanceOf(PressableComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($wireView, $composedViewTreeReading->view->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnActionClickIsKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["actions" => ["reset" => ["type" => "resetState"]], "view" => ["type" => "Pressable", "onClick" => ["action" => "reset"], "children" => [["type" => "Divider"]]]]);
        self::assertSame(["action" => "reset"], $composedViewTreeReading->view->props["onClick"]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAClickOnANameThatIsNoStateNameDropsThePressable(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["view" => ["type" => "Stack", "children" => [["type" => "Pressable", "onClick" => ["set" => "no where"], "children" => [["type" => "Divider"]]]]]]);
        self::assertSame([], $composedViewTreeReading->view->getNodesOfSlot('children'));
        self::assertSame(["UNKNOWN_BINDING", "INVALID_NODE"], $composedViewTreeReading->findings->getCodes());
    }

    public function testAPressableNeverHoldsAnotherPressable(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(["state" => ["picked" => ""], "view" => ["type" => "Pressable", "onClick" => ["set" => "picked"], "children" => [["type" => "Pressable", "onClick" => ["set" => "picked"], "children" => [["type" => "Divider"]]]]]]);
        self::assertSame([], $composedViewTreeReading->view->getNodesOfSlot('children'));
        self::assertSame(["INVALID_NODE", "INVALID_SLOT_NODE_COUNT", "UNREAD_STATE"], array_values(array_unique($composedViewTreeReading->findings->getCodes())), 'the inner Pressable is dropped, the outer one kept without a child; nothing reads the state it sets');
    }
}
