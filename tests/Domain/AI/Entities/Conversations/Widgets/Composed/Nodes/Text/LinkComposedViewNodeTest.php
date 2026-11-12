<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `Link` node: a link with text and url is kept (an `$item` url inside a repeating node too); a
 * link without its url is dropped. The scheme check (http(s) only) is the frontend's, where a bound url resolves.
 */
class LinkComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsALink(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Link', 'text' => 'Artikel öffnen', 'url' => 'https://www.antenne-muenster.de/artikel']]);
        self::assertSame(['type' => 'Link', 'text' => 'Artikel öffnen', 'url' => 'https://www.antenne-muenster.de/artikel'], $composedViewTreeReading->view?->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testALinkWithoutUrlOrWithANumberUrlIsDropped(): void
    {
        $missingUrlReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Link', 'text' => 'Mehr']]);
        self::assertNull($missingUrlReading->view);
        self::assertSame(['INVALID_NODE'], $missingUrlReading->findings->getCodes());

        $numberUrlReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Link', 'text' => 'Mehr', 'url' => 42]]);
        self::assertNull($numberUrlReading->view);
        self::assertSame(['INVALID_PROP', 'INVALID_NODE'], $numberUrlReading->findings->getCodes());
    }

    public function testAnItemUrlOutsideARepeatingNodeIsReported(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Link', 'text' => 'Mehr', 'url' => ['$item' => 'url']]]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['UNKNOWN_BINDING', 'INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }
}
