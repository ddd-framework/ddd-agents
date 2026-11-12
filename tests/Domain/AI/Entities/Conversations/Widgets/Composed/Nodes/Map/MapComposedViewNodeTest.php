<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Map;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Map\MapComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Map` node as the backend reader sees it: a valid map with points, a route and a carousel is
 * kept as written; an unknown prop, a wrong carousel value and a non-list `points` are dropped with their codes; a map
 * without points is dropped; a point bound to a dataset stays a binding.
 */
class MapComposedViewNodeTest extends UnitTestCase
{
    /** @return array<string, mixed> */
    protected function buildMapWireNode(): array
    {
        return [
            'type' => 'Map',
            'title' => 'Cafés in Münster',
            'points' => [
                ['label' => 'KAWENTSMANNs CAFE & TAGESBAR', 'lat' => 51.9625, 'lng' => 7.6270, 'rating' => 4.7, 'subtitle' => 'Café', 'action' => 'openKawentsmann'],
                ['label' => 'roestbar Rothenburg', 'lat' => 51.9588, 'lng' => 7.6262, 'rating' => 4.6],
            ],
            'route' => false,
            'carousel' => 'auto',
            'minHeight' => 330,
        ];
    }

    public function testAValidMapIsKeptAsWritten(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'actions' => ['openKawentsmann' => ['type' => 'openEntity', 'ref' => 'call_1:competitor:0']],
            'view' => $this->buildMapWireNode(),
        ]);
        self::assertInstanceOf(MapComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
        self::assertSame($this->buildMapWireNode(), $composedViewTreeReading->view->toWireArray());
    }

    public function testInvalidPropsAreDroppedWithTheirCodes(): void
    {
        $wireNode = $this->buildMapWireNode();
        $wireNode['carousel'] = 'sometimes';
        $wireNode['zoomLevel'] = 12;
        $wireNode['routePath'] = ['lat' => 51.9];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => $wireNode]);
        self::assertInstanceOf(MapComposedViewNode::class, $composedViewTreeReading->view);
        self::assertArrayNotHasKey('carousel', $composedViewTreeReading->view->props);
        self::assertArrayNotHasKey('zoomLevel', $composedViewTreeReading->view->props);
        self::assertArrayNotHasKey('routePath', $composedViewTreeReading->view->props);
        self::assertSame(['INVALID_PROP', 'INVALID_PROP', 'INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
    }

    public function testAMapWithoutPointsIsDropped(): void
    {
        $wireNode = $this->buildMapWireNode();
        unset($wireNode['points']);
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => $wireNode]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testPointsMayBeBoundToADataset(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'data' => ['places' => [['label' => 'Mocca d\'or', 'lat' => 51.961, 'lng' => 7.628]]],
            'view' => ['type' => 'Map', 'points' => ['$data' => 'places']],
        ]);
        self::assertInstanceOf(MapComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame(['$data' => 'places'], $composedViewTreeReading->view->props['points']);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }
}
