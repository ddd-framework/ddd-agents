<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The `Label` node: a short label with bound text is kept; a label holds no children. */
class LabelComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsABoundLabel(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['channel' => 'Organic'],
            'view' => ['type' => 'Label', 'text' => ['$state' => 'channel']],
        ]);
        self::assertSame(['type' => 'Label', 'text' => ['$state' => 'channel']], $composedViewTreeReading->view?->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testChildrenOfALabelAreDroppedAndAnUnknownStateIsReported(): void
    {
        $childrenReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Label', 'text' => 'Zeitraum', 'children' => [['type' => 'Text', 'text' => 'x']]]]);
        self::assertSame(['type' => 'Label', 'text' => 'Zeitraum'], $childrenReading->view?->toWireArray());
        self::assertSame(['INVALID_NODE'], $childrenReading->findings->getCodes());

        $unknownStateReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Label', 'text' => ['$state' => 'missing']]]);
        self::assertNull($unknownStateReading->view);
        self::assertSame(['UNKNOWN_BINDING', 'INVALID_NODE'], $unknownStateReading->findings->getCodes());
    }
}
