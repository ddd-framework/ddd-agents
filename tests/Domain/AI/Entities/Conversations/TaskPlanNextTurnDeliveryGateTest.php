<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;

/**
 * The delivery gate's NEXT-TURN acceptance: a delivery item ticked at the start of the turn
 * after the delivery is accepted only when the previous turn both read the item's evidence (one of the declared tools
 * completed) and ended on the delivering owner-visible answer — and the delivering message index is recorded on the
 * item so it survives compaction with the persisted plan.
 */
class TaskPlanNextTurnDeliveryGateTest extends UnitTestCase
{
    protected function userMessage(int $index): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->message = 'owner';
        $message->toolCall = null;
        return $message;
    }

    protected function assistantText(int $index, string $text): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->message = $text;
        $message->toolCall = null;
        return $message;
    }

    protected function completedToolCall(int $index, string $toolName): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->role = null;
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->arguments = [];
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $message->toolCall = $toolCall;
        return $message;
    }

    /** @param array<int, AIConversationMessage> $messages */
    protected function messageSet(array $messages): AIConversationMessages
    {
        $messageSet = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($messageSet, 'elements', $messages);
        self::setPropertyValue($messageSet, 'elementCount', count($messages));
        return $messageSet;
    }

    protected function deliveryItemPlan(): TaskPlan
    {
        $taskPlanItem = new TaskPlanItem();
        $taskPlanItem->text = 'Quick win delivered';
        $taskPlanItem->doneRequiresOwnerVisibleAnswerInSameTurn = true;
        $taskPlanItem->previousTurnDeliveryEvidenceToolNames = ['getReputation', 'getCompetitorsVisibility'];
        $taskPlanItems = new TaskPlanItems();
        $taskPlanItems->add($taskPlanItem);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        return $taskPlan;
    }

    /** Previous turn: owner asks (10) → getReputation (11) → delivering answer (12); current turn starts at 13. */
    protected function deliveredPreviousTurn(): AIConversationMessages
    {
        return $this->messageSet([
            $this->userMessage(10),
            $this->completedToolCall(11, 'getReputation'),
            $this->assistantText(12, '4.4 stars from 1,246 reviews — 1,165 unanswered. Next: answer the open ones.'),
            $this->userMessage(13),
        ]);
    }

    public function testEvidenceIndexIsTheDeliveringAnswerOfThePreviousTurn(): void
    {
        self::assertSame(12, $this->deliveredPreviousTurn()->findPreviousTurnDeliveryEvidenceIndex(13, ['getReputation']));
    }

    public function testNoEvidenceWhenTheToolRanBeforeThePreviousTurnOrAfterTheAnswer(): void
    {
        $toolBeforePreviousTurn = $this->messageSet([
            $this->completedToolCall(8, 'getReputation'),
            $this->userMessage(10),
            $this->assistantText(12, 'Here are your numbers.'),
            $this->userMessage(13),
        ]);
        self::assertNull($toolBeforePreviousTurn->findPreviousTurnDeliveryEvidenceIndex(13, ['getReputation']));

        $toolAfterAnswer = $this->messageSet([
            $this->userMessage(10),
            $this->assistantText(11, 'One moment.'),
            $this->completedToolCall(12, 'getReputation'),
            $this->userMessage(13),
        ]);
        self::assertNull($toolAfterAnswer->findPreviousTurnDeliveryEvidenceIndex(13, ['getReputation']), 'the answer must FOLLOW the evidence');
    }

    public function testNoEvidenceWhenThePreviousTurnEndsOnAStatusLineOrAnUnrelatedTool(): void
    {
        $statusOnlyEnd = $this->messageSet([
            $this->userMessage(10),
            $this->completedToolCall(11, 'getReputation'),
            $this->assistantText(12, AIConversationMessage::PRE_CALL_MESSAGE_MARKER . ' reading your reviews'),
            $this->userMessage(13),
        ]);
        self::assertNull($statusOnlyEnd->findPreviousTurnDeliveryEvidenceIndex(13, ['getReputation']));

        $unrelatedTool = $this->messageSet([
            $this->userMessage(10),
            $this->completedToolCall(11, 'getPosts'),
            $this->assistantText(12, 'Your posts look fine.'),
            $this->userMessage(13),
        ]);
        self::assertNull($unrelatedTool->findPreviousTurnDeliveryEvidenceIndex(13, ['getReputation']));
        self::assertNull($this->deliveredPreviousTurn()->findPreviousTurnDeliveryEvidenceIndex(13, []), 'no declared evidence tools → no acceptance');
    }

    public function testNextTurnTickIsAcceptedAndRecordsTheEvidenceIndex(): void
    {
        $taskPlan = $this->deliveryItemPlan();
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => '4.4 stars, 1,165 unanswered']]],
            $taskPlan,
            13,
            $this->deliveredPreviousTurn()
        );
        self::assertSame(1, $result['updatedCount'], implode(' ', $result['feedback']));
        $taskPlanItem = $taskPlan->items->getElements()[0];
        self::assertSame(TaskPlanItem::STATUS_DONE, $taskPlanItem->status);
        self::assertSame(12, $taskPlanItem->evidenceMessageIndex);
    }

    public function testNextTurnTickStaysRejectedWithoutThePreviousTurnDelivery(): void
    {
        $taskPlan = $this->deliveryItemPlan();
        $noDelivery = $this->messageSet([
            $this->userMessage(10),
            $this->assistantText(12, 'Continuing with the competitors.'),
            $this->userMessage(13),
        ]);
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'numbers only here']]],
            $taskPlan,
            13,
            $noDelivery
        );
        self::assertSame(0, $result['updatedCount']);
        self::assertStringContainsString('not applied', implode(' ', $result['feedback']));
        $taskPlanItem = $taskPlan->items->getElements()[0];
        self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlanItem->status);
        self::assertNull($taskPlanItem->evidenceMessageIndex);
    }

    public function testTheNewFieldsSurviveTheRepositoryRoundTrip(): void
    {
        $taskPlanItem = new TaskPlanItem();
        $taskPlanItem->text = 'Quick win delivered';
        $taskPlanItem->previousTurnDeliveryEvidenceToolNames = ['getReputation'];
        $taskPlanItem->evidenceMessageIndex = 12;
        $fields = $taskPlanItem->toRepositoryFields();
        self::assertArrayHasKey('previousTurnDeliveryEvidenceToolNames', $fields);
        self::assertArrayHasKey('evidenceMessageIndex', $fields);
        $rebuilt = TaskPlanItem::fromRepositoryFields($fields);
        self::assertSame(['getReputation'], $rebuilt->previousTurnDeliveryEvidenceToolNames);
        self::assertSame(12, $rebuilt->evidenceMessageIndex);
        $plain = TaskPlanItem::fromRepositoryFields(['text' => 'x']);
        self::assertNull($plain->previousTurnDeliveryEvidenceToolNames);
        self::assertNull($plain->evidenceMessageIndex);
    }

    public function testTheSeedKeyIsParsedAndSurvivesAFullReplaceResend(): void
    {
        $seededItems = TaskPlanItems::fromPurposeSeedDefinition([[
            'text' => 'Quick win delivered',
            'doneRequiresOwnerVisibleAnswerInSameTurn' => true,
            'previousTurnDeliveryEvidenceToolNames' => ['getReputation'],
        ]]);
        $seededItem = $seededItems->getElements()[0];
        self::assertSame(['getReputation'], $seededItem->previousTurnDeliveryEvidenceToolNames);
        self::assertTrue($seededItem->carriesEvidenceGate());
        $seededItem->evidenceMessageIndex = 12;
    }
}
