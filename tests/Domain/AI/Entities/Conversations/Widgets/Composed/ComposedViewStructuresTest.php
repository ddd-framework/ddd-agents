<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewFormSignalHead;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeDeclarations;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeProp;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSlot;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Nodes\Data\ListItemComposedViewNode;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The head of a form signal and the declarations of a node class are value objects: the signal head knows its verb,
 * the declarations keep the class attributes in their order.
 */
class ComposedViewStructuresTest extends UnitTestCase
{
    public function testTheSignalHeadKnowsItsVerb(): void
    {
        $submittedHead = ComposedViewFormSignalHead::forFormNameAndVerb('feedback', 'submitted:');
        $declinedHead = ComposedViewFormSignalHead::forFormNameAndVerb('feedback', 'declined.');

        self::assertSame('feedback', $submittedHead->formName);
        self::assertTrue($submittedHead->isSubmitted());
        self::assertFalse($submittedHead->isDeclined());
        self::assertTrue($declinedHead->isDeclined());
        self::assertFalse($declinedHead->isSubmitted());
    }

    public function testTheDeclarationsOfANodeClassKeepItsAttributesInOrder(): void
    {
        $slotNames = array_map(static fn (ComposedViewNodeSlot $composedViewNodeSlot): string => $composedViewNodeSlot->name, ListItemComposedViewNode::getSlotDeclarations());
        $propNames = array_map(static fn (ComposedViewNodeProp $composedViewNodeProp): string => $composedViewNodeProp->name, ListItemComposedViewNode::getPropDeclarations());

        self::assertSame([ComposedViewNodeSlot::DEFAULT_SLOT_NAME, 'trailing'], $slotNames);
        self::assertSame(['title', 'description', 'meta'], array_slice($propNames, 0, 3));
        self::assertSame(ListItemComposedViewNode::getSlotDeclarations(), ListItemComposedViewNode::getSlotDeclarations(), 'read once per class');
    }

    public function testTheFactoryHoldsTheGivenDeclarations(): void
    {
        $slotDeclarations = ListItemComposedViewNode::getSlotDeclarations();
        $propDeclarations = ListItemComposedViewNode::getPropDeclarations();
        $composedViewNodeDeclarations = ComposedViewNodeDeclarations::forSlotsAndProps($slotDeclarations, $propDeclarations);

        self::assertSame($slotDeclarations, $composedViewNodeDeclarations->slotDeclarations);
        self::assertSame($propDeclarations, $composedViewNodeDeclarations->propDeclarations);
    }
}
