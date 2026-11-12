<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewReadableEntityRows;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolCallResult;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolCallResults;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewLiftedWidgetNodes;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReference;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceRow;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Embedding\WidgetComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\DividerComposedViewNode;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Layout\StackComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The sets of a `ComposedView` block that are read by a key: the tool call results by tool call id, the readable
 * entity rows by reference, the lifted widget nodes by wire path and a node's nodes by slot name.
 */
class ComposedViewSetsReadByKeyTest extends UnitTestCase
{
    public function testAToolCallIdTwoRowsCarryIsAmbiguousAndResolvesNothing(): void
    {
        $composedViewToolCallResults = new ComposedViewToolCallResults();
        $composedViewToolCallResults->addToolCallResult(ComposedViewToolCallResult::fromStoredValues('call_a', 'COMPLETED', 'first'));
        $composedViewToolCallResults->addToolCallResult(ComposedViewToolCallResult::fromStoredValues('call_b', 'COMPLETED', 'only'));
        $composedViewToolCallResults->addToolCallResult(ComposedViewToolCallResult::fromStoredValues('call_a', 'COMPLETED', 'second'));
        $composedViewToolCallResults->addToolCallResult(ComposedViewToolCallResult::fromStoredValues('', 'COMPLETED', 'no id'));

        self::assertSame(2, $composedViewToolCallResults->count(), 'one element per id; a row without an id is not kept');
        self::assertSame('call_b', $composedViewToolCallResults->findToolCallResult('call_b')?->toolCallId);
        self::assertTrue($composedViewToolCallResults->knowsToolCallId('call_a'));
        self::assertTrue($composedViewToolCallResults->isAmbiguous('call_a'));
        self::assertNull($composedViewToolCallResults->findToolCallResult('call_a'), 'an ambiguous id is never picked');
        self::assertSame(['call_a'], $composedViewToolCallResults->ambiguousToolCallIds);
        self::assertFalse($composedViewToolCallResults->knowsToolCallId('call_c'));
        self::assertNull($composedViewToolCallResults->findToolCallResult('call_c'));
    }

    public function testAReadableEntityRowIsFoundByItsReferenceAndTheFirstRowOfAReferenceStays(): void
    {
        $entityReference = EntityReference::create('call_a', 'review', 0);
        $otherEntityReference = EntityReference::create('call_a', 'review', 1);
        $composedViewReadableEntityRows = new ComposedViewReadableEntityRows();
        $composedViewReadableEntityRows->addRowOfReference($entityReference, EntityReferenceRow::create('review', '11', null));
        $composedViewReadableEntityRows->addRowOfReference(EntityReference::create('call_a', 'review', 0), EntityReferenceRow::create('review', '99', null));

        self::assertSame(1, $composedViewReadableEntityRows->count());
        self::assertTrue($composedViewReadableEntityRows->hasRowOfReference($entityReference));
        self::assertSame('11', $composedViewReadableEntityRows->getRowOfReference($entityReference)?->entityId);
        self::assertFalse($composedViewReadableEntityRows->hasRowOfReference($otherEntityReference));
        self::assertNull($composedViewReadableEntityRows->getRowOfReference($otherEntityReference));
    }

    public function testLiftedWidgetNodesKeepTheirWirePathsInReadingOrder(): void
    {
        $composedViewLiftedWidgetNodes = new ComposedViewLiftedWidgetNodes();
        $composedViewLiftedWidgetNodes->addWidgetNodeAtPath('view.children[2]', new WidgetComposedViewNode());
        $composedViewLiftedWidgetNodes->addWidgetNodeAtPath('view.children[0]', new WidgetComposedViewNode());

        self::assertSame(['view.children[2]', 'view.children[0]'], $composedViewLiftedWidgetNodes->getPaths());
        self::assertSame(2, $composedViewLiftedWidgetNodes->count(), 'two nodes of the same class at two paths are two elements');
    }

    public function testANodeHoldsTheNodesOfEachSlotByTheSlotName(): void
    {
        $stackNode = new StackComposedViewNode();
        self::assertSame([], $stackNode->getNodesOfSlots(), 'no slot was read');
        self::assertSame([], $stackNode->getNodesOfSlot('children'));

        $firstDivider = new DividerComposedViewNode();
        $secondDivider = new DividerComposedViewNode();
        $stackNode->setNodesOfSlot('children', [$firstDivider, $secondDivider]);
        $stackNode->setNodesOfSlot('header', []);

        self::assertSame([$firstDivider, $secondDivider], $stackNode->getNodesOfSlot('children'), 'two nodes of the same class stay two nodes');
        self::assertSame([], $stackNode->getNodesOfSlot('header'));
        self::assertSame(['children', 'header'], array_map(static fn ($composedViewNodesOfSlot): string => $composedViewNodesOfSlot->slotName, $stackNode->getNodesOfSlots()));
        self::assertSame(3, $stackNode->countNodes());

        $stackNode->setNodesOfSlot('children', [$secondDivider]);
        self::assertSame([$secondDivider], $stackNode->getNodesOfSlot('children'), 'setting a slot again replaces its nodes');
        self::assertCount(2, $stackNode->getNodesOfSlots());
    }
}
