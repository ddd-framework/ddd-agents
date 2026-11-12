<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Rich\SvgComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Svg` node: literal markup is kept (the frontend sanitises it), markup bound to a computed
 * text is kept, a binding to an undeclared computed value is dropped, and as a `data` node it fits a compact cell.
 */
class SvgComposedViewNodeTest extends UnitTestCase
{
    public function testLiteralMarkupIsKept(): void
    {
        $markup = "<svg viewBox='0 0 100 100'><circle cx='50' cy='50' r='40' fill='#4a90d9'/></svg>";
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => [
            'type' => 'Svg',
            'markup' => $markup,
            'label' => 'A blue circle',
        ]]);
        self::assertInstanceOf(SvgComposedViewNode::class, $composedViewTreeReading->view);
        self::assertSame($markup, $composedViewTreeReading->view->props['markup']);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testMarkupBoundToAComputedTextIsKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['radius' => 40],
            'computed' => ['circle' => "\"<svg viewBox='0 0 100 100'><circle cx='50' cy='50' r='\" + string(radius) + \"'/></svg>\""],
            'view' => ['type' => 'Svg', 'markup' => ['$computed' => 'circle']],
        ]);
        self::assertSame(['$computed' => 'circle'], $composedViewTreeReading->view->props['markup']);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testABindingToAnUndeclaredComputedValueDropsTheNode(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Svg', 'markup' => ['$computed' => 'missing']]]);
        self::assertNull($composedViewTreeReading->view);
        self::assertTrue($composedViewTreeReading->findings->hasCode('INVALID_NODE'));
    }

    public function testASvgFitsACompactCell(): void
    {
        $compactCellSlot = new ComposedViewNodeSlot(acceptsCategories: ['inline', 'block', 'data', 'media', 'input'], sizeClass: ComposedViewNodeSlot::SIZE_CLASS_COMPACT);
        self::assertTrue(SvgComposedViewNode::isTypeAcceptedBySlot($compactCellSlot));
    }
}
