<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The `Caption` node: kept as a root and inline in a `Text`; without text it is dropped. */
class CaptionComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsACaptionAtTheRootAndInsideAText(): void
    {
        $rootReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Caption', 'text' => 'Stand: 19. Oktober 2026']]);
        self::assertSame(['type' => 'Caption', 'text' => 'Stand: 19. Oktober 2026'], $rootReading->view?->toWireArray());
        self::assertSame([], $rootReading->findings->getCodes());

        $inlineReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Text', 'text' => '94', 'children' => [['type' => 'Caption', 'text' => 'Sitze']]]]);
        self::assertSame([], $inlineReading->findings->getCodes());
        self::assertCount(1, $inlineReading->view->getNodesOfSlot('children'));
    }

    public function testACaptionWithoutTextOrWithAnUndeclaredPropIsHandled(): void
    {
        $missingTextReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Caption']]);
        self::assertNull($missingTextReading->view);
        self::assertSame(['INVALID_NODE'], $missingTextReading->findings->getCodes());

        $undeclaredPropReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Caption', 'text' => 'x', 'color' => 'red']]);
        self::assertSame(['type' => 'Caption', 'text' => 'x'], $undeclaredPropReading->view?->toWireArray());
        self::assertSame(['INVALID_PROP'], $undeclaredPropReading->findings->getCodes());
    }
}
