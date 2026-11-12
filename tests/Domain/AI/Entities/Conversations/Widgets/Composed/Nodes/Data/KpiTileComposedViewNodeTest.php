<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The reader keeps a valid KpiTile and drops what its declaration refuses. */
class KpiTileComposedViewNodeTest extends UnitTestCase
{
    public function testAValidTileIsKeptWithItsBoundValue(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'computed' => ['total' => '8200'],
            'view' => ['type' => 'KpiTile', 'label' => 'Visitors', 'value' => ['$computed' => 'total'], 'format' => 'number', 'delta' => 4.2, 'deltaFormat' => 'percent', 'higherIsBetter' => true, 'detail' => 'All channels'],
        ]);
        self::assertSame('KpiTile', $composedViewTreeReading->view::NODE_TYPE);
        self::assertSame(['$computed' => 'total'], $composedViewTreeReading->view->props['value']);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testATileWithoutValueIsDroppedAndAnUnknownFormatIsRefused(): void
    {
        $withoutValue = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'KpiTile', 'label' => 'Visitors']]);
        self::assertNull($withoutValue->view);
        self::assertSame(['INVALID_NODE'], $withoutValue->findings->getCodes());

        $withUnknownFormat = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'KpiTile', 'label' => 'Visitors', 'value' => 8200, 'format' => 'roman', 'colour' => 'red', 'source' => ['name' => 'Google Analytics']]]);
        self::assertSame(['label' => 'Visitors', 'value' => 8200, 'source' => ['name' => 'Google Analytics']], $withUnknownFormat->view->props);
        self::assertSame(['INVALID_PROP', 'INVALID_PROP'], $withUnknownFormat->findings->getCodes());
    }
}
