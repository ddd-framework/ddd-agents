<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The shared fixture builders of the task-plan CHARACTERIZATION tests. These tests pin
 * the behaviour of the task-plan code as it is TODAY (quirks included) so a refactor shows every difference
 * it makes; a failing assertion here after a refactor commit is a behaviour change to be named, not a bug in the test.
 * In-memory only: no DB, no model call. The field lists are those of an application whose item class declares fields
 * of its own ({@see TaskPlanItemOfAnApplicationWithAGuidedSetup}); the tests run with that class in the module
 * class's place.
 */
abstract class TaskPlanCharacterizationTestCase extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBeforeTheItemClass = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBeforeTheItemClass = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[TaskPlanItem::class] = TaskPlanItemOfAnApplicationWithAGuidedSetup::class;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBeforeTheItemClass;
        parent::tearDown();
    }

    /** The persisted item keys in today's order ({@see TaskPlanItem::toRepositoryFields()}); the identity fields stand after the text. */
    protected const array PERSISTED_ITEM_KEYS = [
        'text', 'key', 'highestSubtaskKeyNumber', 'seededByPurpose', 'ownerFacingTitle', 'status', 'statusNote', 'requiresUserAction', 'retryable', 'doneCriterion',
        'doneRequiresToolCall', 'doneRequiresToolCallArgumentsAbsent',
        'doneRequiresToolCallArgumentsPresent', 'notApplicableRequiresToolCall',
        'notApplicableRequiresToolCallArgumentsPresent', 'notApplicableRequiresToolCallArgumentsAbsent',
        'notApplicableForbiddenAfterToolCall',
        'doneRequiresWidget', 'doneRequiresConnectedChannel', 'closedQuestionOptions',
        'minReasoningEffort', 'doneRequiresWidgetFilledFields', 'doneRequiresWidgetAnswered',
        'doneRequiresOwnerVisibleAnswerInSameTurn', 'doneRequiresOwnerQuestionKey', 'doneAlsoOnOwnerAnswerOfWidget', 'doneOwnerQuestionMayFollowToolCall', 'previousTurnDeliveryEvidenceToolNames', 'evidenceMessageIndex',
        'deferrableAtSetupEnd', 'deferredAtSetupEnd', 'offerRenderedAtMessageIndex', 'preResolvedAtSeed', 'platformResolvedAtMessageIndex',
        'blockedRequiresWidget', 'blockedRequiresToolCall',
        'blockedRequiresToolCallArgumentsPresent', 'triggersSupervisorMilestoneCheckpoint', 'completesSetupStepName',
        'confirmsInterfaceLanguage', 'customerProgressStepKey', 'skillNames', 'subtasks',
    ];

    /** The 29 seeded declarations (five were dropped, 5.2's proposal anchor added one), in their declared order. */
    protected const array SEEDED_DECLARATION_FIELDS = [
        'ownerFacingTitle', 'doneCriterion', 'doneRequiresToolCall',
        'doneRequiresToolCallArgumentsAbsent', 'doneRequiresToolCallArgumentsPresent', 'notApplicableRequiresToolCall',
        'notApplicableRequiresToolCallArgumentsAbsent', 'notApplicableRequiresToolCallArgumentsPresent',
        'notApplicableForbiddenAfterToolCall',
        'doneRequiresWidget', 'doneRequiresConnectedChannel', 'closedQuestionOptions',
        'minReasoningEffort', 'doneRequiresWidgetFilledFields', 'doneRequiresWidgetAnswered',
        'doneRequiresOwnerVisibleAnswerInSameTurn', 'doneRequiresOwnerQuestionKey', 'doneAlsoOnOwnerAnswerOfWidget', 'doneOwnerQuestionMayFollowToolCall', 'previousTurnDeliveryEvidenceToolNames', 'blockedRequiresWidget',
        'blockedRequiresToolCall', 'blockedRequiresToolCallArgumentsPresent', 'deferrableAtSetupEnd',
        'triggersSupervisorMilestoneCheckpoint',
        'completesSetupStepName', 'confirmsInterfaceLanguage', 'customerProgressStepKey', 'skillNames',
    ];

    /** The item's identity: the minted key, its subtask counter, the seeded/model distinction. */
    protected const array ITEM_IDENTITY_FIELDS = ['key', 'highestSubtaskKeyNumber', 'seededByPurpose'];

    /** The 5 platform records (the parked done note left). */
    protected const array PLATFORM_RECORD_FIELDS = [
        'evidenceMessageIndex', 'deferredAtSetupEnd', 'offerRenderedAtMessageIndex', 'preResolvedAtSeed',
        'platformResolvedAtMessageIndex',
    ];

    /** The item fields a closing or re-open may write — the columns of the field-write tables. */
    protected const array MUTABLE_ITEM_FIELDS = [
        'status', 'statusNote', 'requiresUserAction', 'retryable',
        'platformResolvedAtMessageIndex', 'evidenceMessageIndex', 'preResolvedAtSeed', 'deferredAtSetupEnd',
        'offerRenderedAtMessageIndex',
    ];

    /** The encoding flags of the plan slice write (`AIConversationsService::persistTaskPlanSlice()`). */
    protected const int PERSISTED_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    protected function item(string $text, string $status = TaskPlanItem::STATUS_OPEN, ?string $statusNote = null): TaskPlanItem
    {
        $taskPlanItem = TaskPlanItem::newInstance();
        $taskPlanItem->text = $text;
        $taskPlanItem->status = $status;
        $taskPlanItem->statusNote = $statusNote;
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

    /** @param TaskPlanItem[] $taskPlanItemList */
    protected function taskPlan(array $taskPlanItemList): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = $this->itemSet($taskPlanItemList);
        return $taskPlan;
    }

    /** @param AIConversationMessage[] $messageList */
    protected function messages(array $messageList): AIConversationMessages
    {
        $messageSet = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($messageSet, 'elements', array_values($messageList));
        self::setPropertyValue($messageSet, 'elementCount', count($messageList));
        return $messageSet;
    }

    protected function ownerText(int $index, string $text): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->message = $text;
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

    /** A widget the platform's own tool wrote (a TOOL_MESSAGE row in the assistant's role). */
    protected function toolWrittenWidget(int $index, string $text): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_MESSAGE;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_TOOL;
        $message->message = $text;
        $message->toolCall = null;
        return $message;
    }

    protected function widgetClick(int $index, string $text): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $message->role = AIConversationMessage::ROLE_SYSTEM;
        $message->signalSource = AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK;
        $message->message = $text;
        return $message;
    }

    /** @param array<string, mixed> $toolCallArguments */
    protected function completedCall(int $index, string $toolName, array $toolCallArguments = []): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_AGENT;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_' . $index;
        $toolCall->name = $toolName;
        $toolCall->arguments = $toolCallArguments;
        $toolCall->markCompleted();
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function widgetFence(string $widgetType, array $extraPayload = []): string
    {
        return "```widget\n" . json_encode(['type' => $widgetType] + $extraPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n```";
    }

    /**
     * The field-write table row of one item: every field a closing or re-open may write, by name.
     *
     * @return array<string, mixed>
     */
    protected function mutableFieldsOf(TaskPlanItem $taskPlanItem): array
    {
        $mutableFields = [];
        foreach (self::MUTABLE_ITEM_FIELDS as $fieldName) {
            $mutableFields[$fieldName] = $taskPlanItem->{$fieldName};
        }
        return $mutableFields;
    }

    /**
     * An item whose mutable fields all carry a recognisable SENTINEL, so a field-write table shows exactly which fields
     * a transition writes and which it leaves untouched.
     */
    protected function sentinelItem(string $text, string $status = TaskPlanItem::STATUS_OPEN, ?int $platformResolvedAtMessageIndex = 3): TaskPlanItem
    {
        $taskPlanItem = $this->item($text, $status, 'SENTINEL note');
        $taskPlanItem->requiresUserAction = true;
        $taskPlanItem->retryable = true;
        $taskPlanItem->platformResolvedAtMessageIndex = $platformResolvedAtMessageIndex;
        $taskPlanItem->evidenceMessageIndex = 77;
        $taskPlanItem->preResolvedAtSeed = true;
        $taskPlanItem->deferredAtSetupEnd = true;
        $taskPlanItem->offerRenderedAtMessageIndex = 66;
        return $taskPlanItem;
    }

    /**
     * The sentinel row with the given fields overridden — the expected value of a field-write table.
     *
     * @param array<string, mixed> $writtenFields
     * @return array<string, mixed>
     */
    protected function sentinelFieldsWith(string $status, array $writtenFields, ?int $platformResolvedAtMessageIndex = 3): array
    {
        return array_merge([
            'status' => $status,
            'statusNote' => 'SENTINEL note',
            'requiresUserAction' => true,
            'retryable' => true,
            'platformResolvedAtMessageIndex' => $platformResolvedAtMessageIndex,
            'evidenceMessageIndex' => 77,
            'preResolvedAtSeed' => true,
            'deferredAtSetupEnd' => true,
            'offerRenderedAtMessageIndex' => 66,
        ], $writtenFields);
    }
}
