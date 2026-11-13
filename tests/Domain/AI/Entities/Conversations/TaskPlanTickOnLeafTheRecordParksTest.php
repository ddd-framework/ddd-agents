<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteriaWithFixedVerdicts;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A done / not_applicable tick on a criterion leaf whose record verdict is BLOCKED (the owner's recorded words park
 * the leaf) is never applied: a leaf the platform already parked keeps its park untouched, any other leaf takes the
 * record's park. The call never fails; the feedback names the record's verdict.
 */
class TaskPlanTickOnLeafTheRecordParksTest extends UnitTestCase
{
    protected const string RECORD_PARK_NOTE = 'parked: the owner will name the address later';

    protected function items(): TaskPlanItems
    {
        return TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Address', 'doneCriterion' => 'identity.address'],
            ['text' => 'Goals'],
        ]);
    }

    protected function plan(TaskPlanItems $items): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = $items;
        return $taskPlan;
    }

    protected function userMessages(): AIConversationMessages
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = 1;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->message = 'hello';
        $messages = new AIConversationMessages();
        $messages->add($message);
        return $messages;
    }

    protected function criteriaParkingTheAddress(): TaskPlanDoneCriteriaWithFixedVerdicts
    {
        return TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey([
            'identity.address' => TaskPlanDoneCriterionVerdict::blockedByOwnerStatement(self::RECORD_PARK_NOTE),
        ]);
    }

    /** @return array{updatedCount: int, notedCount: int, feedback: string[]} */
    protected function tick(TaskPlan $taskPlan, string $status, int $currentTurnInitiatorMessageIndex = 9): array
    {
        return SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => $status, 'statusNote' => 'no address needed']]],
            $taskPlan,
            $currentTurnInitiatorMessageIndex,
            $this->userMessages(),
            taskPlanDoneCriteria: $this->criteriaParkingTheAddress()
        );
    }

    public function testANotApplicableOnALeafThePlatformParkedLeavesThePark(): void
    {
        $items = $this->items();
        $taskPlan = $this->plan($items);
        $addressLeaf = $items->getElements()[0];
        $addressLeaf->closeFromCriterionVerdict(TaskPlanDoneCriterionVerdict::blockedByOwnerStatement(self::RECORD_PARK_NOTE), 3);

        $result = $this->tick($taskPlan, 'not_applicable');

        self::assertSame(0, $result['updatedCount']);
        self::assertSame(1, $result['notedCount']);
        self::assertSame(TaskPlanItem::STATUS_BLOCKED, $addressLeaf->status);
        self::assertSame(self::RECORD_PARK_NOTE, $addressLeaf->statusNote);
        self::assertTrue($addressLeaf->requiresUserAction);
        self::assertSame(3, $addressLeaf->platformResolvedAtMessageIndex, 'the park keeps the stamp of the moment the platform set it');
        self::assertStringContainsString(
            "Task '1': the record decides this item — blocked (your not_applicable was not applied): " . self::RECORD_PARK_NOTE,
            implode(' ', $result['feedback'])
        );
        self::assertStringNotContainsString('REJECTED', implode(' ', $result['feedback']));
    }

    public function testADoneOnALeafThePlatformParkedLeavesThePark(): void
    {
        $items = $this->items();
        $taskPlan = $this->plan($items);
        $addressLeaf = $items->getElements()[0];
        $addressLeaf->closeFromCriterionVerdict(TaskPlanDoneCriterionVerdict::blockedByOwnerStatement(self::RECORD_PARK_NOTE), 3);

        $result = $this->tick($taskPlan, 'done');

        self::assertSame(0, $result['updatedCount']);
        self::assertSame(1, $result['notedCount']);
        self::assertSame(TaskPlanItem::STATUS_BLOCKED, $addressLeaf->status);
        self::assertSame(self::RECORD_PARK_NOTE, $addressLeaf->statusNote);
        self::assertStringContainsString('blocked (your done was not applied)', implode(' ', $result['feedback']));
    }

    public function testANotApplicableOnAnOpenLeafTheRecordParksTakesTheRecordsPark(): void
    {
        $items = $this->items();
        $taskPlan = $this->plan($items);
        $addressLeaf = $items->getElements()[0];

        $result = $this->tick($taskPlan, 'not_applicable');

        self::assertSame(1, $result['updatedCount']);
        self::assertSame(0, $result['notedCount']);
        self::assertSame(TaskPlanItem::STATUS_BLOCKED, $addressLeaf->status);
        self::assertSame(self::RECORD_PARK_NOTE, $addressLeaf->statusNote, 'the record\'s note, not the model\'s');
        self::assertTrue($addressLeaf->requiresUserAction, 'the park is the owner\'s decision');
        self::assertFalse($addressLeaf->retryable);
        self::assertSame(9, $addressLeaf->platformResolvedAtMessageIndex);
        self::assertTrue($addressLeaf->isParkedByThePlatform());
    }

    public function testANotApplicableOnALeafTheModelBlockedTakesTheRecordsPark(): void
    {
        $items = $this->items();
        $taskPlan = $this->plan($items);
        $addressLeaf = $items->getElements()[0];
        $addressLeaf->applyStatusChosenByTheModel(TaskPlanItem::STATUS_BLOCKED, 'the geocoder failed', false, true);
        self::assertFalse($addressLeaf->isParkedByThePlatform(), 'a model-set blocked is no platform park');

        $result = $this->tick($taskPlan, 'not_applicable');

        self::assertSame(1, $result['updatedCount']);
        self::assertSame(TaskPlanItem::STATUS_BLOCKED, $addressLeaf->status);
        self::assertSame(self::RECORD_PARK_NOTE, $addressLeaf->statusNote);
        self::assertTrue($addressLeaf->requiresUserAction);
        self::assertFalse($addressLeaf->retryable);
        self::assertSame(9, $addressLeaf->platformResolvedAtMessageIndex);
    }

    public function testATickOnALeafWithoutCriterionIsNotReadAgainstTheRecord(): void
    {
        $items = $this->items();
        $taskPlan = $this->plan($items);
        $goalsLeaf = $items->getElements()[1];

        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '2', 'status' => 'not_applicable', 'statusNote' => 'the owner named no goal']]],
            $taskPlan,
            9,
            $this->userMessages(),
            taskPlanDoneCriteria: $this->criteriaParkingTheAddress()
        );

        self::assertSame(1, $result['updatedCount']);
        self::assertSame(TaskPlanItem::STATUS_NOT_APPLICABLE, $goalsLeaf->status);
    }
}
