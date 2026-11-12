<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The `Title` node: the reader keeps a heading with its level, drops an unknown level and a title without text. */
class TitleComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsATitleWithItsLevel(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Title', 'text' => 'Rechnerische Koalitionsmöglichkeiten', 'level' => 'section']]);
        self::assertSame(['type' => 'Title', 'text' => 'Rechnerische Koalitionsmöglichkeiten', 'level' => 'section'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnUnknownLevelIsDroppedAndTheTitleStays(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Title', 'text' => 'Visits', 'level' => 'huge']]);
        self::assertSame(['type' => 'Title', 'text' => 'Visits'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame(['INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
    }

    public function testATitleWithoutTextIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Title', 'level' => 'widget']]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testTheLevelTakesNoBinding(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['level' => 'section'],
            'view' => ['type' => 'Title', 'text' => ['$state' => 'level'], 'level' => ['$state' => 'level']],
        ]);
        self::assertSame(['type' => 'Title', 'text' => ['$state' => 'level']], $composedViewTreeReading->view?->toWireArray());
        self::assertSame(['INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
    }
}
