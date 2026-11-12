<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewTreeReading;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The Table slot contract: rows hold cells, cells hold compact nodes, Repeat repeats rows. */
class TableComposedViewNodeTest extends UnitTestCase
{
    public function testTheShortFormIsKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'data' => ['coalitions' => [['name' => 'CSU + Freie Wähler', 'seats' => 94]]],
            'view' => ['type' => 'Table', 'columns' => [['key' => 'name', 'label' => 'Coalition'], ['key' => 'seats', 'label' => 'Seats', 'align' => 'end']], 'rows' => ['$data' => 'coalitions'], 'source' => ['name' => 'landtag.bayern.de', 'url' => 'https://www.bayern.landtag.de/']],
        ]);
        self::assertSame('Table', $composedViewTreeReading->view::NODE_TYPE);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
    }

    public function testTheNodeFormKeepsRowsCellsAndARepeatWithItemBindings(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields([
            'data' => ['coalitions' => [['name' => 'CSU + SPD', 'seats' => 86, 'majority' => false]]],
            'view' => ['type' => 'Table', 'source' => ['name' => 'landtag.bayern.de', 'url' => 'https://www.bayern.landtag.de/'], 'children' => [
                ['type' => 'TableRow', 'header' => true, 'children' => [['type' => 'TableCell', 'value' => 'Coalition'], ['type' => 'TableCell', 'value' => 'Majority']]],
                ['type' => 'Repeat', 'items' => ['$data' => 'coalitions'], 'children' => [
                    ['type' => 'TableRow', 'children' => [
                        ['type' => 'TableCell', 'value' => ['$item' => 'name']],
                        ['type' => 'TableCell', 'children' => [['type' => 'Icon', 'name' => 'close', 'tone' => 'danger', 'label' => 'No majority']]],
                    ]],
                ]],
            ]],
        ]);
        self::assertSame([], $composedViewTreeReading->findings->getCodes());
        $repeatNode = $composedViewTreeReading->view->getNodesOfSlot('children')[1];
        self::assertSame('Repeat', $repeatNode::NODE_TYPE);
        self::assertSame('Icon', $repeatNode->getNodesOfSlot('children')[0]->getNodesOfSlot('children')[1]->getNodesOfSlot('children')[0]::NODE_TYPE);
    }

    public function testAForeignNodeInTheTableACellOutsideARowAndATableInACellAreDropped(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Table', 'source' => ['name' => 'landtag.bayern.de', 'url' => 'https://www.bayern.landtag.de/'], 'children' => [
            ['type' => 'Icon', 'name' => 'check'],
            ['type' => 'TableRow', 'children' => [
                ['type' => 'TableCell', 'value' => 1],
                ['type' => 'TableRow', 'children' => [['type' => 'TableCell', 'value' => 2]]],
                ['type' => 'TableCell', 'children' => [['type' => 'TableRow', 'children' => [['type' => 'TableCell', 'value' => 3]]]]],
            ]],
        ]]]);
        self::assertSame(['INVALID_NODE', 'INVALID_NODE', 'INVALID_NODE'], $composedViewTreeReading->findings->getCodes());
        $tableRowNode = $composedViewTreeReading->view->getNodesOfSlot('children')[0];
        self::assertCount(2, $tableRowNode->getNodesOfSlot('children'));
        self::assertSame([], $tableRowNode->getNodesOfSlot('children')[1]->getNodesOfSlot('children'));
    }

    public function testARowWithoutCellsIsReportedAndKept(): void
    {
        $composedViewTreeReading = ComposedViewTreeReading::fromWidgetFields(['view' => ['type' => 'Table', 'children' => [['type' => 'TableRow']]]]);
        self::assertSame(['INVALID_SLOT_NODE_COUNT'], $composedViewTreeReading->findings->getCodes());
        self::assertCount(1, $composedViewTreeReading->view->getNodesOfSlot('children'), 'the row is kept: a note, never a dropped node');
    }
}
