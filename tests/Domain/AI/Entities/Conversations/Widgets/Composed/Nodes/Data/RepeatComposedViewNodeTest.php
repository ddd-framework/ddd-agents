<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** Repeat opens the item scope; $item outside it is refused. */
class RepeatComposedViewNodeTest extends UnitTestCase
{
    public function testItemBindingsInsideARepeatAreKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'data' => ['channels' => [['name' => 'Organic', 'visitors' => 4190]]],
            'view' => ['type' => 'Repeat', 'items' => ['$data' => 'channels'], 'limit' => 3, 'children' => [
                ['type' => 'KpiTile', 'label' => ['$item' => 'name'], 'value' => ['$item' => 'visitors'], 'source' => ['name' => 'Google Analytics']],
            ]],
        ]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
        self::assertSame(['$item' => 'name'], $composedViewTreeReading->view->getNodesOfSlot('children')[0]->props['label']);
    }

    public function testAnItemBindingOutsideARepeatAndARepeatWithoutItemsAreRefused(): void
    {
        $outsideItemScope = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'KpiTile', 'label' => 'x', 'value' => ['$item' => 'value']]]);
        self::assertNull($outsideItemScope->view);
        self::assertSame(['UNKNOWN_BINDING', 'INVALID_NODE'], $outsideItemScope->findings->getCodes());

        $withoutItems = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Repeat', 'children' => [['type' => 'Icon', 'name' => 'star']]]]);
        self::assertNull($withoutItems->view);
        self::assertSame(['INVALID_NODE'], $withoutItems->findings->getCodes());
    }
}
