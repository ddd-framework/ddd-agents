<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** List takes items or ListItem children (and Repeats of them), nothing else. */
class ListComposedViewNodeTest extends UnitTestCase
{
    public function testTheShortFormAndTheNodeFormAreKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'data' => ['reviews' => [['author' => 'Anna', 'text' => 'Great coffee']]],
            'view' => ['type' => 'List', 'marker' => 'bullet', 'items' => ['Open on Sunday', ['title' => 'Parking', 'description' => 'Behind the building']], 'children' => [
                ['type' => 'ListItem', 'title' => 'Wi-Fi', 'slots' => ['trailing' => [['type' => 'Icon', 'name' => 'check', 'tone' => 'success']]]],
                ['type' => 'Repeat', 'items' => ['$data' => 'reviews'], 'children' => [['type' => 'ListItem', 'title' => ['$item' => 'author'], 'description' => ['$item' => 'text']]]],
            ]],
        ]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
        self::assertCount(2, $composedViewTreeReading->view->getNodesOfSlot('children'));
    }

    public function testAForeignChildAndAnUnknownMarkerAreDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'List', 'marker' => 'arrow', 'children' => [['type' => 'Icon', 'name' => 'check']]]]);
        self::assertSame([], $composedViewTreeReading->view->getNodesOfSlot('children'));
        self::assertSame(['INVALID_PROP', 'INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
    }
}
