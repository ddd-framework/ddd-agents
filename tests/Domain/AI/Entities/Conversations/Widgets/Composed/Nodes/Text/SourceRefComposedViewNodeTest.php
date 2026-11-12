<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Text;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `SourceRef` node: a list of sources with licence fields is kept as written; an object instead of
 * a list drops the node (its required prop is gone); a nested binding inside a source is checked like any binding.
 */
class SourceRefComposedViewNodeTest extends UnitTestCase
{
    public function testTheReaderKeepsTheSourcesWithTheirLicenceFields(): void
    {
        $sources = [
            ['name' => 'WDR', 'url' => 'https://www1.wdr.de/nachrichten'],
            ['name' => 'Wikimedia Commons', 'url' => 'https://commons.wikimedia.org/wiki/File:Koelner_Dom.jpg', 'title' => 'Kölner Dom', 'author' => 'Raimond Spekking', 'license' => 'CC BY-SA 4.0', 'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
        ];
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'SourceRef', 'sources' => $sources]]);
        self::assertSame(['type' => 'SourceRef', 'sources' => $sources], $composedViewTreeReading->view?->toWireArray());
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testAnObjectInsteadOfAListDropsTheNode(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'SourceRef', 'sources' => ['name' => 'WDR']]]);
        self::assertNull($composedViewTreeReading->view);
        self::assertSame(['INVALID_PROP', 'INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }

    public function testANestedBindingInASourceIsChecked(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'state' => ['publisher' => 'WDR'],
            'view' => ['type' => 'SourceRef', 'sources' => [['name' => ['$state' => 'publisher']]]],
        ]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());

        $unknownReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'SourceRef', 'sources' => [['name' => ['$state' => 'publisher']]]]]);
        self::assertNull($unknownReading->view);
        self::assertSame(['UNKNOWN_BINDING', 'INVALID_NODE'], $unknownReading->findings->getCodes());
    }
}
