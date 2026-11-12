<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanLeafClosings;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Every consumer that turns a displayed number back into an item resolves by KEY, never by position. The
 * fixture is a keyed tree whose keys differ from the positions (built from a stored row):
 * item 2's subtasks stand in the order 2.1, 2.3, 2.2, so the POSITION "2.2" is the item keyed "2.3"
 * ("Thank the 5-star reviewers") and the KEY "2.2" is the third subtask ("Draft and post the replies"). Each consumer
 * must hit the item its key names; render order and `← next` follow the stored order.
 */
class TaskPlanKeyAddressingTest extends UnitTestCase
{
    protected const string THANK = 'Thank the 5-star reviewers';
    protected const string DRAFT = 'Draft and post the replies';

    /**
     * @param array<string, mixed> $replyFields stored fields of the item keyed "2.2" (replacing the defaults)
     * @param array<string, mixed> $thankFields stored fields of the item keyed "2.3"
     */
    protected function keyedPlan(array $replyFields = [], array $thankFields = []): TaskPlan
    {
        return TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Set the holiday hours', 'key' => '1', 'status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'saved'],
            ['text' => 'Answer the newest reviews', 'key' => '2', 'status' => TaskPlanItem::STATUS_OPEN, 'highestSubtaskKeyNumber' => 3, 'subtasks' => [
                ['text' => 'Read the newest reviews', 'key' => '2.1', 'status' => TaskPlanItem::STATUS_DONE, 'statusNote' => '3 new'],
                array_replace(['text' => self::THANK, 'key' => '2.3', 'status' => TaskPlanItem::STATUS_OPEN], $thankFields),
                array_replace(['text' => self::DRAFT, 'key' => '2.2', 'status' => TaskPlanItem::STATUS_OPEN], $replyFields),
            ]],
            ['text' => 'Write the holiday post', 'key' => '3', 'status' => TaskPlanItem::STATUS_OPEN, 'highestSubtaskKeyNumber' => 2, 'subtasks' => [
                ['text' => 'Draft the post', 'key' => '3.1', 'status' => TaskPlanItem::STATUS_OPEN],
                ['text' => 'Publish it', 'key' => '3.2', 'status' => TaskPlanItem::STATUS_OPEN],
            ]],
        ], 'highestItemKeyNumber' => 3]);
    }

    protected function itemText(TaskPlan $taskPlan, string $key): string
    {
        return $taskPlan->items->getItemByKey($key)->text;
    }

    protected function message(int $index, string $role, string $authorType, string $text): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        return $message;
    }

    /** @param array<string, mixed> $arguments */
    protected function toolCallMessage(int $index, string $toolName, array $arguments, string $status = AIConversationMessage::STATUS_COMPLETED): AIConversationMessage
    {
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->status = $status;
        $toolCall->result = '{"content":[]}';
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function messages(AIConversationMessage ...$messageList): AIConversationMessages
    {
        $messages = new AIConversationMessages();
        foreach ($messageList as $message) {
            $messages->add($message);
        }
        return $messages;
    }

    public function testTheFixtureKeysDifferFromThePositions(): void
    {
        $taskPlan = $this->keyedPlan();
        self::assertSame(self::THANK, $taskPlan->items->getItemByNumberPath('2.2')->text, 'position 2.2');
        self::assertSame(self::DRAFT, $this->itemText($taskPlan, '2.2'), 'key 2.2');
        self::assertNull($taskPlan->keyIntegrityViolations, 'a keyed tree in order');
    }

    public function testTheTickHitsTheItemTheKeyNames(): void
    {
        $taskPlan = $this->keyedPlan();
        $result = SetTaskStatusTestTick::apply(['updates' => [['task' => '2.2', 'status' => 'done', 'statusNote' => 'posted 3']]], $taskPlan, 0);

        self::assertSame(1, $result['updatedCount']);
        self::assertSame(TaskPlanItem::STATUS_DONE, $taskPlan->items->getItemByKey('2.2')->status);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlan->items->getItemByKey('2.3')->status, 'the item at position 2.2 stays open');
    }

    public function testRenderOrderAndTheNextMarkerFollowTheStoredOrderNeverTheKey(): void
    {
        $taskPlan = $this->keyedPlan();
        self::assertSame([
            '[✓] 1 Set the holiday hours — saved',
            '[ ] 2 Answer the newest reviews',
            '  [✓] 2.1 Read the newest reviews — 3 new',
            '  [ ] 2.3 ' . self::THANK . ' ← next',
            '  [ ] 2.2 ' . self::DRAFT,
            '[ ] 3 Write the holiday post',
            '  [ ] 3.1 Draft the post',
            '  [ ] 3.2 Publish it',
        ], $taskPlan->items->buildTailHintLines());
        self::assertSame(self::THANK, $taskPlan->items->getNextMarkedItem()->text);
        self::assertSame(['2.3', '2.2', '3.1', '3.2'], $taskPlan->items->getOpenItemNumberPaths(), 'the open keys, in stored order');
    }

    public function testTheCascadePreCountCountsTheItemsTheKeysName(): void
    {
        // five top-level keys 1, 3, 4, 5, 6 at the positions 1–5: by position "6" named nothing (four targets, no
        // cascade); by key it names the fifth item — five distinct targets, above the cap
        $taskPlan = TaskPlan::fromRepositoryArray(['items' => array_map(
            static fn (string $key): array => ['text' => 'Step ' . $key, 'key' => $key, 'status' => TaskPlanItem::STATUS_OPEN],
            ['1', '3', '4', '5', '6']
        ), 'highestItemKeyNumber' => 6]);
        $result = SetTaskStatusTestTick::apply(
            ['updates' => array_map(
            static fn (string $key): array => ['task' => $key, 'status' => 'blocked', 'statusNote' => 'obstructed'],
            ['1', '3', '4', '5', '6']
        )],
            $taskPlan,
            0
        );

        self::assertSame(5, $result['notedCount']);
        self::assertStringContainsString('5 blocked update(s) (1, 3, 4, 5, 6) — that is a give-up cascade', implode(' ', $result['feedback']));
    }

    public function testTheSameTurnEchoCheckComparesTheKey(): void
    {
        $echoFields = ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'closed by the platform', 'platformResolvedAtMessageIndex' => 11];
        $ownerTurn = $this->message(10, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Go on');

        // first tick of "2.2" in the turn: the echo of what the platform closed
        $taskPlan = $this->keyedPlan($echoFields);
        $result = SetTaskStatusTestTick::apply(['updates' => [['task' => '2.2', 'status' => 'done']]], $taskPlan, 10, $this->messages($ownerTurn));
        self::assertSame(["Task '2.2' was closed by the platform in this turn — it is done, nothing to do."], $result['feedback']);

        // an earlier tick of THIS key in the turn: no longer an echo
        $earlierTickOfTheKey = $this->toolCallMessage(12, SetTaskStatusTool::NAME, ['updates' => [['task' => '2.2', 'status' => 'done']]]);
        $taskPlan = $this->keyedPlan($echoFields);
        $result = SetTaskStatusTestTick::apply(['updates' => [['task' => '2.2', 'status' => 'done']]], $taskPlan, 10, $this->messages($ownerTurn, $earlierTickOfTheKey));
        self::assertStringContainsString("Task '2.2' is ALREADY done", implode(' ', $result['feedback']));

        // an earlier tick of the key "2.3" (the item at position 2.2) names another item
        $earlierTickOfAnotherKey = $this->toolCallMessage(12, SetTaskStatusTool::NAME, ['updates' => [['task' => '2.3', 'status' => 'done']]]);
        self::assertFalse($this->messages($ownerTurn, $earlierTickOfAnotherKey)->buildCurrentTurn()->hasTaskPlanTickNamingTask('2.2'));
        self::assertTrue($this->messages($ownerTurn, $earlierTickOfTheKey)->buildCurrentTurn()->hasTaskPlanTickNamingTask('2.2'));
    }

    public function testTheClosersAndTheirNotesNameTheKey(): void
    {
        $taskPlan = $this->keyedPlan(['doneRequiresToolCall' => 'replyToReview']);
        $messages = $this->messages(
            $this->message(10, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Reply'),
            $this->toolCallMessage(12, 'replyToReview', ['reviewId' => 7])
        );

        self::assertSame(['2.2'], $taskPlan->items->getItemNumberPathsTickableAfterToolCall('replyToReview', TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: false)));
        $closings = $taskPlan->items->closeLeavesProvenByCompletedCall('replyToReview', 12, TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: false));
        self::assertSame(['2.2' => TaskPlanItem::STATUS_DONE], $closings->getStatusByNumberPath());
        self::assertSame(TaskPlanItem::STATUS_DONE, $taskPlan->items->getItemByKey('2.2')->status);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlan->items->getItemByKey('2.3')->status);

        self::assertSame(
            'PLAN: the platform closed plan item 2.2 (done) from the record — do NOT tick it.',
            TaskPlan::buildNoteAfterCompletedToolCall($closings, [])
        );
        self::assertSame(
            "PLAN: at the end of your last turn the platform closed these plan items from the record:\n"
            . "- 2.2 → done (closed by the platform: replyToReview COMPLETED @12)\n"
            . 'They show as terminal in your task plan — do NOT tick them again; continue with the next open item.',
            TaskPlan::buildNoteForLeavesClosedAtTurnEnd($closings, $taskPlan->items)
        );
    }

    public function testADuplicateStoredKeyAddressesNothingAndIsLeftOutOfTheOpenKeyList(): void
    {
        $taskPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'First', 'key' => '1', 'status' => TaskPlanItem::STATUS_OPEN],
            ['text' => 'Second', 'key' => '2', 'status' => TaskPlanItem::STATUS_OPEN],
            ['text' => 'Second again', 'key' => '2', 'status' => TaskPlanItem::STATUS_OPEN],
        ], 'highestItemKeyNumber' => 2]);
        $storedBytes = json_encode($taskPlan->mapToRepository());

        self::assertSame(['"2" appears twice'], $taskPlan->keyIntegrityViolations->lines);
        self::assertSame(2, $taskPlan->items->countItemsWithKey('2'));
        self::assertNull($taskPlan->items->getItemByKey('2'), 'never the first match');
        self::assertSame(["1 'First'"], $taskPlan->items->getOpenItemKeyLines());

        $result = SetTaskStatusTestTick::apply(
            ['updates' => [
            ['task' => '1', 'status' => 'done', 'statusNote' => 'x'],
            ['task' => '2', 'status' => 'done', 'statusNote' => 'x'],
        ]],
            $taskPlan,
            0
        );
        self::assertSame(0, $result['updatedCount']);
        self::assertSame([
            "Task status NOT updated — nothing changed. Fix every line below and send the call again:\n"
            . "- Task '2' names more than one item in your stored plan — the platform has logged the defect; use another key.\n"
            . "Your open items: 1 'First'",
        ], $result['feedback']);
        self::assertSame($storedBytes, json_encode($taskPlan->mapToRepository()), 'nothing applied — the plan is byte-identical');
    }

    public function testTheLeafClosingsCarryTheKeysTheyWereGiven(): void
    {
        $closings = TaskPlanLeafClosings::ofStatusByNumberPath(['2.2' => TaskPlanItem::STATUS_DONE]);
        self::assertSame(['2.2'], $closings->getClosedNumberPaths());
    }
}
