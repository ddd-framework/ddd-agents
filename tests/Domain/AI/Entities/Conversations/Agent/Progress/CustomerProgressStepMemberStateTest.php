<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Progress;

use DDD\Domain\AI\Entities\Conversations\Agent\Progress\CustomerProgressStepMemberState;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The member items of one customer progress step are counted into one value object: open members, members the dialog
 * resolved, and members the platform resolved before the first turn.
 */
class CustomerProgressStepMemberStateTest extends UnitTestCase
{
    public function testAStateWithoutMembersIsNeitherClosedNorInProgress(): void
    {
        $customerProgressStepMemberState = CustomerProgressStepMemberState::forNoMembers();

        self::assertFalse($customerProgressStepMemberState->hasMembers());
        self::assertFalse($customerProgressStepMemberState->hasOnlyClosedMembers());
        self::assertFalse($customerProgressStepMemberState->hasProgress());
    }

    public function testOpenMembersKeepTheStepOpenAndOnlyDialogResolvedMembersCountAsProgress(): void
    {
        $customerProgressStepMemberState = CustomerProgressStepMemberState::forNoMembers();
        $customerProgressStepMemberState->addMember(false, true);

        self::assertSame(1, $customerProgressStepMemberState->memberCount);
        self::assertTrue($customerProgressStepMemberState->hasOnlyClosedMembers());
        self::assertFalse($customerProgressStepMemberState->hasProgress(), 'a member resolved before the first turn is no progress');

        $customerProgressStepMemberState->addMember(false, false);
        self::assertTrue($customerProgressStepMemberState->hasProgress());

        $customerProgressStepMemberState->addMember(true, false);
        self::assertSame(3, $customerProgressStepMemberState->memberCount);
        self::assertSame(1, $customerProgressStepMemberState->openMemberCount);
        self::assertSame(1, $customerProgressStepMemberState->progressMemberCount);
        self::assertFalse($customerProgressStepMemberState->hasOnlyClosedMembers());
    }

    public function testThePlanTreeCountsOneStatePerStepKey(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Identify the business', 'customerProgressStepKey' => 'identify'],
            [
                'text' => 'Profile round',
                'customerProgressStepKey' => 'profile',
                'subtasks' => [['text' => 'Contact details'], ['text' => 'Opening hours']],
            ],
            ['text' => 'No stepper membership'],
        ]);
        $memberStatesByStepKey = [];
        $taskPlanItems->collectCustomerProgressStepMemberStates($memberStatesByStepKey);

        self::assertSame(['identify', 'profile'], array_keys($memberStatesByStepKey));
        self::assertContainsOnlyInstancesOf(CustomerProgressStepMemberState::class, $memberStatesByStepKey);
        self::assertSame(1, $memberStatesByStepKey['identify']->memberCount);
        self::assertSame(3, $memberStatesByStepKey['profile']->memberCount, 'the parent and both subtasks carry the key');
        self::assertSame(3, $memberStatesByStepKey['profile']->openMemberCount);
    }
}
