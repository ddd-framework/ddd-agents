<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Entity;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `EntityLink` node: a link with text and the name of an `openEntity` action is kept (a bound action
 * name inside a repeating node too); a link without text is dropped; a link without action keeps its text; an invalid
 * variant or a non-string action is dropped from the props, the node stays.
 */
class EntityLinkComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsAnEntityLinkWithItsOpenEntityAction(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'actions' => ['openRoma' => ['type' => 'openEntity', 'ref' => 'call_7:competitor:0']],
            'view' => ['type' => 'EntityLink', 'text' => 'Pizzeria Roma', 'action' => 'openRoma', 'variant' => 'chip'],
        ]);
        self::assertSame(['type' => 'EntityLink', 'text' => 'Pizzeria Roma', 'action' => 'openRoma', 'variant' => 'chip'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testALinkWithoutActionKeepsItsText(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'EntityLink', 'text' => 'Pizzeria Roma']]);
        self::assertSame(['type' => 'EntityLink', 'text' => 'Pizzeria Roma'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testALinkWithoutTextIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'EntityLink', 'action' => 'openRoma']]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnInvalidVariantOrANumberActionIsDroppedAndTheNodeStays(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'EntityLink', 'text' => 'Roma', 'action' => 7, 'variant' => 'button']]);
        self::assertSame(['type' => 'EntityLink', 'text' => 'Roma'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame(['INVALID_PROP', 'INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
    }

    public function testABoundActionNameInsideARepeatIsKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'data' => ['competitors' => [['name' => 'Pizzeria Roma', 'action' => 'openRoma']]],
            'actions' => ['openRoma' => ['type' => 'openEntity', 'ref' => 'call_7:competitor:0']],
            'view' => [
                'type' => 'Repeat',
                'items' => ['$data' => 'competitors'],
                'children' => [['type' => 'EntityLink', 'text' => ['$item' => 'name'], 'action' => ['$item' => 'action']]],
            ],
        ]);
        self::assertSame(['$item' => 'action'], $composedViewTreeReading->view?->getNodesOfSlot('children')[0]->props['action'] ?? null);
        self::assertNotContains('INVALID_NODE', $composedViewTreeReading->findings->getCodes());
    }

    public function testAnItemBoundActionOutsideARepeatingNodeIsReported(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'EntityLink', 'text' => 'Roma', 'action' => ['$item' => 'action']]]);
        self::assertSame(['type' => 'EntityLink', 'text' => 'Roma'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame(['UNKNOWN_BINDING'], $composedViewTreeReading->findings->getCodes());
    }
}
