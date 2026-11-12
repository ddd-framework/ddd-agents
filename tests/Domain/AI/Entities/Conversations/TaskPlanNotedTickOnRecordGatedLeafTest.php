<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteriaWithFixedVerdicts;

/**
 * A tick never fails the call. A tick the record cannot confirm on a record-gated
 * leaf is not applied, the leaf stays open, the reason stands in the feedback; a `done` is parked as the
 * model's note for the delivery path of the same turn and dropped at the turn end when the record still lacks the
 * evidence; not_applicable and blocked park nothing; a note-less blocked / not_applicable applies with the omission
 * named.
 */
class TaskPlanNotedTickOnRecordGatedLeafTest extends UnitTestCase
{
    protected function items(): TaskPlanItems
    {
        return TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Profile round', 'subtasks' => [
                ['text' => 'Marketing history'],
                ['text' => 'Social links', 'doneRequiresToolCall' => 'updateProfileSocialMediaLinks'],
            ]],
            ['text' => 'Find your business', 'doneRequiresWidget' => 'BusinessMatchConfirm', 'doneRequiresWidgetAnswered' => true],
            ['text' => 'Goals', 'blockedRequiresToolCall' => 'addOwnerStatement', 'blockedRequiresToolCallArgumentsPresent' => ['topics' => ['goals'], 'kind' => ['DEFERRAL', 'DECLINE']]],
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
        $message->message = 'hallo';
        $messages = new AIConversationMessages();
        $messages->add($message);
        return $messages;
    }

    public function testADoneOnAToolGatedLeafIsNotedAndNothingOfItIsKept(): void
    {
        $items = $this->items();
        $taskPlan = $this->plan($items);
        $socialLeaf = $items->getElements()[0]->subtasks->getElements()[1];

        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1.2', 'status' => 'done', 'statusNote' => 'instagram stored']]],
            $taskPlan,
            5,
            $this->userMessages()
        );
        self::assertSame(0, $result['updatedCount']);
        self::assertSame(1, $result['notedCount']);
        self::assertStringContainsString("Task '1.2': done not applied", implode(' ', $result['feedback']));
        self::assertStringNotContainsString('REJECTED', implode(' ', $result['feedback']));
        self::assertSame(TaskPlanItem::STATUS_OPEN, $socialLeaf->status);
        self::assertSame(5, $taskPlan->updatedAtTurnInitiatorMessageIndex, 'a plan touch: the checked stop arms');
        self::assertNotSame(5, $taskPlan->resolvedAtTurnInitiatorMessageIndex, 'never progress');

        $secondTick = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1.2', 'status' => 'done', 'statusNote' => 'instagram stored, really']]],
            $taskPlan,
            5,
            $this->userMessages()
        );
        self::assertSame(1, $secondTick['notedCount']);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $socialLeaf->status, 'the record closes the leaf, never the claim');
    }

    public function testADoneOnAToolGatedLeafTheRecordAlreadyResolvesLandsAsTheRecordsVerdict(): void
    {
        // in a measured run, the page read stored the verified social links (no model call), the model ticked the social
        // leaf done — the tool gate NOTED it and the leaf ended blocked. The record decides before the tool gate.
        $items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Profile round', 'subtasks' => [
                ['text' => 'Social links', 'doneRequiresToolCall' => 'updateProfileSocialMediaLinks', 'doneCriterion' => 'social.links'],
            ]],
        ]);
        $taskPlan = $this->plan($items);
        $socialLeaf = $items->getElements()[0]->subtasks->getElements()[0];
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1.1', 'status' => 'done', 'statusNote' => 'links already on the profile']]],
            $taskPlan,
            5,
            $this->userMessages(),
            taskPlanDoneCriteria: TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey([
                'social.links' => TaskPlanDoneCriterionVerdict::done('already satisfied: social links on the profile (facebook, instagram)'),
            ])
        );
        self::assertSame(1, $result['updatedCount']);
        self::assertSame(0, $result['notedCount']);
        self::assertSame(TaskPlanItem::STATUS_DONE, $socialLeaf->status);
        self::assertStringStartsWith('already satisfied: social links', (string)$socialLeaf->statusNote, 'the record\'s note, not the model\'s');
        self::assertSame(5, $socialLeaf->platformResolvedAtMessageIndex);
        self::assertStringContainsString("Task '1.1': done — confirmed by the record", implode(' ', $result['feedback']));
    }

    public function testAReadThatStoredSomethingOnTheRecordSaysSo(): void
    {
        $toolCall = new ToolCall();
        $toolCall->name = 'getWebPageAsMarkdown';
        $toolCall->result = 'PLATFORM STORED the social profile links verified on the business\'s own site: facebook https://facebook.com/x';
        self::assertTrue($toolCall->platformStoredSomethingOnTheRecord());
        $toolCall->result = '{"content":[{"type":"text","text":"# Page"}]}';
        self::assertFalse($toolCall->platformStoredSomethingOnTheRecord());
    }

    public function testADoneOnAWidgetTheOwnerAnswersIsNoted(): void
    {
        $items = $this->items();
        $taskPlan = $this->plan($items);
        $identityLeaf = $items->getElements()[1];
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '2', 'status' => 'done', 'statusNote' => 'widget shown']]],
            $taskPlan,
            5,
            $this->userMessages()
        );
        self::assertSame(1, $result['notedCount']);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $identityLeaf->status);
    }

    public function testABlockedWithoutTheOwnersRecordedWordsIsNoted(): void
    {
        $items = $this->items();
        $taskPlan = $this->plan($items);
        $goalsLeaf = $items->getElements()[2];
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '3', 'status' => 'blocked', 'requiresUserAction' => true, 'statusNote' => 'owner deferred']]],
            $taskPlan,
            5,
            $this->userMessages()
        );
        self::assertSame(0, $result['updatedCount']);
        self::assertSame(1, $result['notedCount']);
        self::assertStringContainsString("Task '3': blocked not applied", implode(' ', $result['feedback']));
        self::assertSame(TaskPlanItem::STATUS_OPEN, $goalsLeaf->status);
    }

    public function testANoteLessNotApplicableAppliesWithTheOmissionNamed(): void
    {
        $items = $this->items();
        $taskPlan = $this->plan($items);
        $marketingLeaf = $items->getElements()[0]->subtasks->getElements()[0];
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1.1', 'status' => 'not_applicable']]],
            $taskPlan,
            5,
            $this->userMessages()
        );
        self::assertSame(1, $result['updatedCount']);
        self::assertSame(TaskPlanItem::STATUS_NOT_APPLICABLE, $marketingLeaf->status);
        self::assertStringContainsString("Task '1.1': not_applicable without a statusNote", implode(' ', $result['feedback']));
        self::assertStringNotContainsString('REJECTED', implode(' ', $result['feedback']));
    }
}
