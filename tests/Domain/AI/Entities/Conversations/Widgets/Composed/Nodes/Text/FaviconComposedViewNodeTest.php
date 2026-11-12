<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The `Favicon` node: a site icon by url or domain with its name is kept; without url it is dropped. */
class FaviconComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsAFavicon(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Favicon', 'url' => 'wdr.de', 'name' => 'WDR']]);
        self::assertSame(['type' => 'Favicon', 'url' => 'wdr.de', 'name' => 'WDR'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAFaviconWithoutUrlIsDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Favicon', 'name' => 'WDR']]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }
}
