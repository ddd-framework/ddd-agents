<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich\MathComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Math` node: a formula with its display, heading and caption is kept; an unknown display, an
 * undeclared prop and children are dropped with their codes; a node without `latex` is dropped.
 */
class MathComposedViewNodeTest extends UnitTestCase
{
    public function testAValidFormulaIsKeptWithItsProps(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => [
            'type' => 'Math',
            'latex' => 'CR = \\frac{N_{Conversions}}{N_{Visitors}} \\times 100\\%',
            'display' => 'block',
            'title' => 'Conversion Rate',
            'caption' => 'Example: 750 conversions from 25,000 visitors give 3 %.',
        ]]);
        self::assertInstanceOf(MathComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame('block', $composedViewTreeReading->view->props['display']);
        self::assertSame('Conversion Rate', $composedViewTreeReading->view->props['title']);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnUnknownDisplayAnUndeclaredPropAndChildrenAreDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => [
            'type' => 'Math',
            'latex' => 'a^2 + b^2 = c^2',
            'display' => 'fullscreen',
            'color' => 'red',
            'children' => [['type' => 'Math', 'latex' => 'x']],
        ]]);
        self::assertInstanceOf(MathComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame(['latex' => 'a^2 + b^2 = c^2'], $composedViewTreeReading->view->props);
        self::assertSame([], $composedViewTreeReading->view->getNodesOfSlots());
        self::assertSame(['INVALID_PROP', 'INVALID_PROP', 'INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testAFormulaWithoutLatexIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Math', 'title' => 'Empty']]);
        self::assertNull($composedViewTreeReading->view);
        self::assertTrue($composedViewTreeReading->findings->hasCode('INVALID_NODE'));
    }

    public function testTheDisplayTakesNoBinding(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['mode' => 'inline'],
            'view' => ['type' => 'Math', 'latex' => 'x', 'display' => ['$state' => 'mode']],
        ]);
        self::assertSame(['latex' => 'x'], $composedViewTreeReading->view->props);
        self::assertSame(['INVALID_PROP'], $composedViewTreeReading->findings->getCodes());
    }
}
