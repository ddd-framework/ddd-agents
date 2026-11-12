<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\ToolCalls;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\AgentToolResultScreeningService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw read of {@see AgentToolResultScreeningService} against the database, on both engines: which pending
 * results the screening job removed meanwhile, read from the stored `toolCall` column of the call rows.
 */
class AgentToolResultScreeningServiceDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 100;

    protected AgentToolResultScreeningService $screeningService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->screeningService = new AgentToolResultScreeningService();
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID, 'runState' => AIConversationRunState::RUNNING]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testOnlyTheResultsTheJobRemovedAreReportedAndEveryOtherVerdictIsTakenOver(): void
    {
        $storedToolCallByMessageId = [
            701 => ['name' => 'readPage', 'status' => 'completed', 'injectionScreening' => ToolCall::INJECTION_SCREENING_REMOVED, 'injectionProbability' => 0.93],
            702 => ['name' => 'readPage', 'status' => 'completed', 'injectionScreening' => ToolCall::INJECTION_SCREENING_CLEAN, 'injectionProbability' => 0.01],
            703 => ['name' => 'readPage', 'status' => 'completed', 'injectionScreening' => ToolCall::INJECTION_SCREENING_PENDING],
            704 => ['name' => 'readPage', 'status' => 'completed', 'injectionScreening' => null, 'injectionProbability' => null],
            705 => ['name' => 'readPage', 'status' => 'completed', 'injectionScreening' => ToolCall::INJECTION_SCREENING_REMOVED, 'injectionProbability' => null],
        ];
        $pendingCallMessages = [];
        $indexInConversation = 0;
        foreach ($storedToolCallByMessageId as $messageId => $storedToolCall) {
            $this->insertRow(DBAIConversationMessageModel::getTableName(), [
                'id' => $messageId,
                'aiConversationId' => self::CONVERSATION_ID,
                'indexInConversation' => $indexInConversation++,
                'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
                'role' => AIConversationMessage::ROLE_ASSISTANT,
                'message' => '',
                'toolCall' => json_encode($storedToolCall),
            ]);
            $pendingCallMessages[$messageId] = $this->buildPendingCallMessage($messageId);
        }

        $removedCallMessages = $this->screeningService->findCallMessagesRemovedSinceLastRead(array_values($pendingCallMessages));

        $removedMessageIds = array_map(static fn (AIConversationMessage $removedCallMessage): int => (int)$removedCallMessage->id, $removedCallMessages);
        sort($removedMessageIds);
        self::assertSame([701, 705], $removedMessageIds);
        self::assertSame(ToolCall::RESULT_REMOVED_FOR_SECURITY, $pendingCallMessages[701]->toolCall->result);
        self::assertEqualsWithDelta(0.93, $pendingCallMessages[701]->toolCall->injectionProbability, 0.0001);
        self::assertNull($pendingCallMessages[705]->toolCall->injectionProbability, 'a JSON null probability reads as no probability on both engines');
        self::assertSame(ToolCall::INJECTION_SCREENING_CLEAN, $pendingCallMessages[702]->toolCall->injectionScreening);
        self::assertSame('the page text', $pendingCallMessages[702]->toolCall->result);
        self::assertSame(ToolCall::INJECTION_SCREENING_PENDING, $pendingCallMessages[703]->toolCall->injectionScreening);
        self::assertSame(ToolCall::INJECTION_SCREENING_PENDING, $pendingCallMessages[704]->toolCall->injectionScreening, 'a JSON null screening is no verdict');
        self::assertSame([], $this->screeningService->findCallMessagesRemovedSinceLastRead([]));
    }

    protected function buildPendingCallMessage(int $messageId): AIConversationMessage
    {
        $toolCall = new ToolCall();
        $toolCall->name = 'readPage';
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->result = 'the page text';
        $toolCall->injectionScreening = ToolCall::INJECTION_SCREENING_PENDING;
        $callMessage = new AIConversationMessage();
        $callMessage->id = $messageId;
        $callMessage->aiConversationId = self::CONVERSATION_ID;
        $callMessage->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $callMessage->toolCall = $toolCall;
        return $callMessage;
    }
}
