<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanLeafClosings;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;

/**
 * What the model is told about the plan after a work tool, and how a tick of an item the platform resolved is
 * answered: the note is built from the plan as it is AFTER the platform's look, a tick sent together with the
 * resolving call is no failure, and the platform's note on an item survives a tick that changes nothing.
 */
class TaskPlanNoteAfterToolCallTest extends UnitTestCase
{
    protected function item(string $text, ?string $doneRequiresToolCall = null, ?string $doneCriterion = null): TaskPlanItem
    {
        $taskPlanItem = new TaskPlanItem();
        $taskPlanItem->text = $text;
        $taskPlanItem->status = TaskPlanItem::STATUS_OPEN;
        $taskPlanItem->doneRequiresToolCall = $doneRequiresToolCall;
        $taskPlanItem->doneCriterion = $doneCriterion;
        return $taskPlanItem;
    }

    /** @param TaskPlanItem[] $taskPlanItemList */
    protected function itemSet(array $taskPlanItemList): TaskPlanItems
    {
        $taskPlanItems = new TaskPlanItems();
        foreach ($taskPlanItemList as $taskPlanItem) {
            $taskPlanItems->add($taskPlanItem);
        }
        return $taskPlanItems;
    }

    protected function resolveByPlatform(TaskPlanItem $taskPlanItem, int $atMessageIndex): void
    {
        $taskPlanItem->status = TaskPlanItem::STATUS_DONE;
        $taskPlanItem->statusNote = 'already satisfied: stage written on the record';
        $taskPlanItem->preResolvedAtSeed = true;
        $taskPlanItem->platformResolvedAtMessageIndex = $atMessageIndex;
    }

    protected function completedCall(int $index, string $toolName, array $arguments = []): AIConversationMessage
    {
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->result = '{"content":[]}';
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->toolCall = $toolCall;
        return $message;
    }

    /** @param AIConversationMessage[] $messageList */
    protected function messages(array $messageList): AIConversationMessages
    {
        $messages = new AIConversationMessages();
        foreach ($messageList as $message) {
            $messages->add($message);
        }
        return $messages;
    }

    public function testTheNoteNamesWhatThePlatformClosedWithItsVerdictAndAsksForNoTick(): void
    {
        self::assertSame(
            'PLAN: the platform closed plan item 2.2 (done) from the record — do NOT tick it.',
            TaskPlan::buildNoteAfterCompletedToolCall(TaskPlanLeafClosings::ofStatusByNumberPath(['2.2' => 'done']), [])
        );
    }

    public function testTheNoteAsksForTheTickOfTheItemsTheModelCloses(): void
    {
        self::assertSame(
            'PLAN: the platform closed plan items 5.1 (done), 5.2 (not_applicable) from the record — do NOT tick them.'
            . ' GATE SATISFIED: this call is the done-evidence of plan item 9 — tick it done via set_task_status NOW'
            . ' (statusNote: what was persisted).',
            TaskPlan::buildNoteAfterCompletedToolCall(TaskPlanLeafClosings::ofStatusByNumberPath(['5.1' => 'done', '5.2' => 'not_applicable']), ['9'])
        );
    }

    public function testNoNoteWhenTheCallChangedNothingTheModelHasToKnow(): void
    {
        self::assertNull(TaskPlan::buildNoteAfterCompletedToolCall(TaskPlanLeafClosings::ofStatusByNumberPath([]), []));
    }

    public function testAnItemThePlatformDecidesFromItsRecordIsNotOfferedForTheTick(): void
    {
        $stageParent = $this->item('Audience');
        $stageParent->subtasks = $this->itemSet([
            $this->item('Presence', 'writeAudienceStage', 'audience.presence'),
            $this->item('Reach', 'writeAudienceStage', 'audience.reach'),
        ]);
        $taskPlanItems = $this->itemSet([
            $stageParent,
            $this->item('Owner reachability recorded', 'updateOwnerReachability|addOwnerStatement'),
        ]);
        $messages = $this->messages([
            $this->completedCall(1, 'writeAudienceStage'),
            $this->completedCall(2, 'addOwnerStatement'),
        ]);

        // a purpose whose criteria decide ticks: the stage items close from the record, never by a tick
        self::assertSame([], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('writeAudienceStage', TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: true)));
        // a purpose whose criteria only pre-tick: the model's tick is accepted, so it is asked for
        self::assertSame(['1.1', '1.2'], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('writeAudienceStage', TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: false)));
        // an item without a criterion is the model's in every purpose; alternatives of the gate count
        self::assertSame(['2'], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('addOwnerStatement', TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: true)));
        self::assertSame([], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('getProfileSummary', TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: false)));
    }

    public function testAnItemWhoseTickWouldBeRefusedIsNotOfferedForTheTick(): void
    {
        $goalItem = $this->item('Goal stated by the owner', 'addGoal');
        $goalItem->doneRequiresToolCallArgumentsAbsent = ['statedByOwner' => [false]];
        $handoverItem = $this->item('Handover delivered', 'scheduleOneTimeTask');
        $handoverItem->doneRequiresWidget = 'SetupHandover';
        $offerItem = $this->item('Directory offer', 'publishListings');
        $offerItem->doneRequiresOwnerVisibleAnswerInSameTurn = true;
        $closedItem = $this->item('Owner reachability recorded', 'updateOwnerReachability');
        $closedItem->status = TaskPlanItem::STATUS_DONE;
        $taskPlanItems = $this->itemSet([$goalItem, $handoverItem, $offerItem, $closedItem]);

        $proposalOnly = $this->messages([
            $this->completedCall(1, 'addGoal', ['statedByOwner' => false]),
            $this->completedCall(2, 'scheduleOneTimeTask'),
            $this->completedCall(3, 'publishListings'),
            $this->completedCall(4, 'updateOwnerReachability'),
        ]);
        self::assertSame([], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('addGoal', TaskPlanEvidence::forTurn($proposalOnly, criteriaDecideTicks: false)), 'a proposal is no evidence');
        self::assertSame([], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('scheduleOneTimeTask', TaskPlanEvidence::forTurn($proposalOnly, criteriaDecideTicks: false)), 'the widget is owed first');
        self::assertSame([], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('publishListings', TaskPlanEvidence::forTurn($proposalOnly, criteriaDecideTicks: false)), 'the answer is owed first');
        self::assertSame([], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('updateOwnerReachability', TaskPlanEvidence::forTurn($proposalOnly, criteriaDecideTicks: false)), 'a closed item');

        $statedByOwner = $this->messages([$this->completedCall(1, 'addGoal', ['statedByOwner' => true])]);
        self::assertSame(['1'], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('addGoal', TaskPlanEvidence::forTurn($statedByOwner, criteriaDecideTicks: false)));
    }

    public function testAnItemThePlatformClosedWithAnotherVerdictStaysTheModelsWhenTheCallIsItsEvidence(): void
    {
        $creationOffer = $this->item('Google profile creation offer', 'publishProfileToGoogle', 'google.creationOffer');
        $creationOffer->status = TaskPlanItem::STATUS_NOT_APPLICABLE;
        $creationOffer->statusNote = 'a Google listing exists';
        $creationOffer->preResolvedAtSeed = true;
        $taskPlanItems = $this->itemSet([$creationOffer]);
        $messages = $this->messages([$this->completedCall(1, 'publishProfileToGoogle')]);

        self::assertSame(['1'], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('publishProfileToGoogle', TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: false), ['1']));
        // closed in an earlier step: the verdict stands
        self::assertSame([], $taskPlanItems->getItemNumberPathsTickableAfterToolCall('publishProfileToGoogle', TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: false), []));
    }

    public function testATickOfAnItemAWidgetClosedOneStepEarlierInTheTurnIsNoFailure(): void
    {
        // the handover widget (a tool-written widget) closed the item at message 15, the model's next step began at
        // 20 and ticks it — an echo of what the model saw, not a loop: no FAILED result, no re-tick lecture
        $handoverItem = $this->item('Handover', null, 'setup.handover');
        $handoverItem->status = TaskPlanItem::STATUS_DONE;
        $handoverItem->statusNote = 'closed by the platform: SetupHandover widget rendered';
        $handoverItem->platformResolvedAtMessageIndex = 15;
        $taskPlan = new TaskPlan();
        $taskPlan->items = $this->itemSet([$handoverItem]);

        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'handover widget delivered']]],
            $taskPlan,
            3,
            new AIConversationMessages(),
            null,
            []
        );

        self::assertSame(0, $result['updatedCount']);
        self::assertTrue($result['onlyResolvedByPlatformInThisStep']);
        self::assertFalse($result['allAlreadyInStatus']);
        self::assertStringNotContainsString('Do NOT re-tick', implode(' ', $result['feedback']));

        // the same tick one turn later (the turn began after the resolution) is the plain ALREADY no-op
        $laterResult = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done']]],
            $taskPlan,
            30,
            new AIConversationMessages(),
            null,
            []
        );
        self::assertTrue($laterResult['allAlreadyInStatus']);
        self::assertFalse($laterResult['onlyResolvedByPlatformInThisStep']);
    }

    public function testATickSentWithTheResolvingCallIsNoFailureAndKeepsThePlatformNote(): void
    {
        // the gate's evidence (the completed stage call) is not part of this test: the item carries its criterion only
        $stageItem = $this->item('Presence', null, 'audience.presence');
        $this->resolveByPlatform($stageItem, 12);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $this->itemSet([$stageItem, $this->item('Reach', 'writeAudienceStage', 'audience.reach')]);

        // the model's response began at message 12: the platform resolved the item at or after it
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'presence stage written']]],
            $taskPlan,
            3,
            new AIConversationMessages(),
            null,
            []
        );

        self::assertSame(0, $result['updatedCount']);
        self::assertTrue($result['onlyResolvedByPlatformInThisStep']);
        self::assertFalse($result['allAlreadyInStatus'], 'it does not count toward the re-tick brake');
        self::assertSame(["Task '1' was closed by the platform in this turn — it is done, nothing to do."], $result['feedback']);
        self::assertStringNotContainsString('Do NOT re-tick', implode(' ', $result['feedback']));
        self::assertSame('already satisfied: stage written on the record', $stageItem->statusNote);

        foreach ([['task' => '1', 'status' => 'done'], ['task' => '1', 'status' => 'completed']] as $updateWithoutNote) {
            $resultWithoutNote = SetTaskStatusTestTick::apply(
                ['updates' => [$updateWithoutNote]],
                $taskPlan,
                3,
                new AIConversationMessages(),
                null,
                []
            );
            self::assertTrue($resultWithoutNote['onlyResolvedByPlatformInThisStep']);
            self::assertCount(1, $resultWithoutNote['feedback'], 'no warning about the note, no normalisation notice');
        }
    }

    public function testATickOfAnItemThePlatformClosedAsNotApplicableWithDoneIsAChangeAndApplies(): void
    {
        $offerItem = $this->item('Google profile creation offer');
        $offerItem->status = TaskPlanItem::STATUS_NOT_APPLICABLE;
        $offerItem->statusNote = 'a Google listing exists';
        $offerItem->preResolvedAtSeed = true;
        $offerItem->platformResolvedAtMessageIndex = 12;
        $taskPlan = new TaskPlan();
        $taskPlan->items = $this->itemSet([$offerItem]);

        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'profile created on Google']]],
            $taskPlan,
            3,
            new AIConversationMessages(),
            null,
            []
        );
        self::assertSame(1, $result['updatedCount']);
        self::assertFalse($result['onlyResolvedByPlatformInThisStep']);
        self::assertSame(TaskPlanItem::STATUS_DONE, $offerItem->status);
        self::assertFalse($offerItem->preResolvedAtSeed, 'the verdict is the model\'s now');
    }

    public function testASecondTickOfAnItemThePlatformClosedInTheTurnIsStillCorrected(): void
    {
        // the gate's evidence (the completed stage call) is not part of this test: the item carries its criterion only
        $stageItem = $this->item('Presence', null, 'audience.presence');
        $this->resolveByPlatform($stageItem, 8);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $this->itemSet([$stageItem, $this->item('Reach', 'writeAudienceStage', 'audience.reach')]);

        // the turn began at 3; an earlier tick of the same item (message 10) already echoed the closing — the
        // second tick in the turn is the re-tick loop the ALREADY corrective exists for
        $ownerMessage = new AIConversationMessage();
        $ownerMessage->indexInConversation = 3;
        $ownerMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerMessage->role = AIConversationMessage::ROLE_USER;
        $ownerMessage->message = 'go on';
        $earlierTick = $this->completedCall(10, SetTaskStatusTool::NAME, ['updates' => [['task' => '1', 'status' => 'done']]]);
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'presence stage written']]],
            $taskPlan,
            3,
            $this->messages([$ownerMessage, $earlierTick]),
            null,
            []
        );

        self::assertSame(0, $result['updatedCount']);
        self::assertFalse($result['onlyResolvedByPlatformInThisStep']);
        self::assertTrue($result['allAlreadyInStatus']);
        self::assertStringContainsString("Task '1' is ALREADY done", implode(' ', $result['feedback']));
        self::assertSame('already satisfied: stage written on the record', $stageItem->statusNote, 'the platform note stays');
    }

    public function testAMixedCallIsNotReportedAsResolvedInThisStepOnly(): void
    {
        $resolvedInThisStep = $this->item('Presence', null, 'audience.presence');
        $this->resolveByPlatform($resolvedInThisStep, 12);
        $closedByTheModel = $this->item('Greeting sent');
        $closedByTheModel->status = TaskPlanItem::STATUS_DONE;
        $closedByTheModel->statusNote = 'greeted';
        $taskPlan = new TaskPlan();
        $taskPlan->items = $this->itemSet([$resolvedInThisStep, $closedByTheModel, $this->item('Reach')]);

        $result = SetTaskStatusTestTick::apply(
            ['updates' => [
                ['task' => '1', 'status' => 'done', 'statusNote' => 'presence stage written'],
                ['task' => '2', 'status' => 'done', 'statusNote' => 'greeted the owner by name'],
            ]],
            $taskPlan,
            3,
            new AIConversationMessages(),
            null,
            []
        );

        self::assertFalse($result['onlyResolvedByPlatformInThisStep']);
        self::assertTrue($result['allAlreadyInStatus']);
        self::assertSame('greeted the owner by name', $closedByTheModel->statusNote, 'the model refreshes its own note');
    }

    public function testTheNewestModelStepBeginsAtTheNewestRowWithAUsageStamp(): void
    {
        $messages = new AIConversationMessages();
        foreach ([[0, 0], [1, 9000], [2, 0], [3, 9500], [4, 0]] as [$index, $inputTokens]) {
            $message = new AIConversationMessage();
            $message->indexInConversation = $index;
            $message->inputTokens = $inputTokens;
            $messages->add($message);
        }
        self::assertSame(3, $messages->getNewestModelStepHeadMessageIndex());
        self::assertNull((new AIConversationMessages())->getNewestModelStepHeadMessageIndex());
    }

    public function testThePlanHeaderStatesThatAClosedItemIsNeverTicked(): void
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = $this->itemSet([$this->item('Presence')]);
        self::assertStringContainsString('closes itself when done — its line says so; never tick it done, and mark it blocked or not_applicable only for an obstacle or a reason its record cannot show; never tick an item listed under ✓ done again', $taskPlan->toTailHintText());
    }
}
