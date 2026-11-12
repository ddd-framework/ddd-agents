<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallStoredOutcome;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The statements of {@see AIConversationMessagesService} that write and read ONE part of a row's JSON columns,
 * against the database, on both engines: the claim of a tool call by a fork-join child and its outcome write, the
 * status of a `delegate` call, the merge of a child's result slice, the life of a sub-agent record (spawn, start, end,
 * notification stamp, continuation) and the reads over those rows.
 *
 * Every compare-and-set is asserted twice: the first call wins, the same call again answers false — on MariaDB
 * (changed rows) and on PostgreSQL (matched rows). A stored document is compared after decoding, never as text: a
 * JSON write on PostgreSQL does not keep the key order.
 */
class AIConversationMessagesToolCallAndSubAgentRecordDatabaseTest extends DatabaseTestCase
{
    /**
     * @param string|null $expectedStatus
     * @param string|null $expectedResult
     * @param ToolCallStoredOutcome|null $toolCallStoredOutcome
     * @return void
     */
    protected function assertStoredOutcome(?string $expectedStatus, ?string $expectedResult, ?ToolCallStoredOutcome $toolCallStoredOutcome): void
    {
        self::assertNotNull($toolCallStoredOutcome);
        self::assertSame([$expectedStatus, $expectedResult], [$toolCallStoredOutcome->status, $toolCallStoredOutcome->result]);
    }

    protected const int PARENT_CONVERSATION_ID = 100;

    protected const int DELEGATE_CALL_MESSAGE_ID = 501;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::PARENT_CONVERSATION_ID]);
    }

    public function testAForkJoinChildClaimsTheCallOnceAndOnlyTheHolderOfTheLeaseWritesTheOutcome(): void
    {
        $toolCallArguments = ['zeta' => 1, 'alpha' => ['second' => 'ö "quoted"', 'first' => [3, 2, 1]]];
        $this->insertToolCallRow(601, 1, ['toolCallId' => 'call_1', 'name' => 'readPage', 'arguments' => $toolCallArguments, 'status' => AIConversationMessage::STATUS_PENDING]);
        $this->insertToolCallRow(602, 2, ['toolCallId' => 'call_2', 'name' => 'readPage', 'status' => AIConversationMessage::STATUS_PENDING], ['truncatedAt' => '2026-01-02 03:04:05']);
        $messagesService = $this->getMessagesService();

        self::assertTrue($messagesService->claimToolCallExecutionLease(601, 'lease-of-the-first-delivery'));
        self::assertFalse($messagesService->claimToolCallExecutionLease(601, 'lease-of-the-second-delivery'), 'a claimed call is not claimed again');
        self::assertFalse($messagesService->claimToolCallExecutionLease(602, 'lease'), 'a truncated row is never claimed');

        $storedToolCall = $this->findStoredJsonColumn(601, 'toolCall');
        self::assertSame(AIConversationMessage::STATUS_RUNNING, $storedToolCall['status']);
        self::assertSame('lease-of-the-first-delivery', $storedToolCall['executionLease']);
        self::assertEquals($toolCallArguments, $storedToolCall['arguments'], 'the rest of the document stays');
        self::assertSame('call_1', $storedToolCall['toolCallId']);
        self::assertSame(AIConversationMessage::STATUS_RUNNING, $messagesService->lockAndFindPersistedToolCallStatus($this->buildMessage(601)));
        $this->assertStoredOutcome(AIConversationMessage::STATUS_RUNNING, null, $messagesService->findPersistedToolCallOutcome(601));

        $callMessage = $this->buildMessage(601);
        $executedToolCall = new ToolCall();
        $executedToolCall->toolCallId = 'call_1';
        $executedToolCall->name = 'readPage';
        $executedToolCall->arguments = $toolCallArguments;
        $executedToolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $executedToolCall->result = 'the page says "hello" — ünï';
        $callMessage->toolCall = $executedToolCall;

        self::assertFalse($messagesService->writeToolCallOutcomeIfLeaseHeld($callMessage, 'lease-of-the-second-delivery'));
        self::assertTrue($messagesService->writeToolCallOutcomeIfLeaseHeld($callMessage, 'lease-of-the-first-delivery'));
        self::assertFalse($messagesService->writeToolCallOutcomeIfLeaseHeld($callMessage, 'lease-of-the-first-delivery'), 'the call no longer runs');
        $this->assertStoredOutcome(AIConversationMessage::STATUS_COMPLETED, 'the page says "hello" — ünï', $messagesService->findPersistedToolCallOutcome(601));
    }

    public function testThePersistedOutcomeReadsAJsonNullAndAMissingKeyAsNoValue(): void
    {
        $this->insertToolCallRow(601, 1, ['name' => 'readPage', 'status' => null, 'result' => null]);
        $this->insertToolCallRow(602, 2, ['name' => 'readPage']);
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => 603,
            'aiConversationId' => self::PARENT_CONVERSATION_ID,
            'indexInConversation' => 3,
            'message' => 'a text row',
        ]);
        $messagesService = $this->getMessagesService();

        $this->assertStoredOutcome(null, null, $messagesService->findPersistedToolCallOutcome(601));
        $this->assertStoredOutcome(null, null, $messagesService->findPersistedToolCallOutcome(602));
        self::assertNull($messagesService->findPersistedToolCallOutcome(603), 'a row without a tool call');
        self::assertNull($messagesService->findPersistedToolCallOutcome(999));
        self::assertNull($messagesService->lockAndFindPersistedToolCallStatus($this->buildMessage(602)));
    }

    public function testADelegateCallStartsOnlyWhilePendingAndEndsOnlyWhileUnresolved(): void
    {
        $this->insertToolCallRow(self::DELEGATE_CALL_MESSAGE_ID, 1, ['name' => DelegateTool::NAME, 'arguments' => ['task' => 'look it up'], 'status' => AIConversationMessage::STATUS_PENDING]);
        $this->insertToolCallRow(601, 2, ['name' => 'readPage', 'status' => AIConversationMessage::STATUS_PENDING]);
        $messagesService = $this->getMessagesService();

        self::assertTrue($messagesService->markDelegateCallRunningIfPending(self::DELEGATE_CALL_MESSAGE_ID));
        self::assertFalse($messagesService->markDelegateCallRunningIfPending(self::DELEGATE_CALL_MESSAGE_ID));
        self::assertFalse($messagesService->markDelegateCallRunningIfPending(601), 'only a delegate call');
        self::assertTrue($messagesService->resolveDelegateCallStatusIfUnresolved(self::DELEGATE_CALL_MESSAGE_ID, AIConversationMessage::STATUS_COMPLETED));
        self::assertFalse(
            $messagesService->resolveDelegateCallStatusIfUnresolved(self::DELEGATE_CALL_MESSAGE_ID, AIConversationMessage::STATUS_FAILED),
            'a resolved call keeps its status'
        );
        self::assertFalse($messagesService->resolveDelegateCallStatusIfUnresolved(601, AIConversationMessage::STATUS_COMPLETED), 'only a delegate call');

        $storedDelegateCall = $this->findStoredJsonColumn(self::DELEGATE_CALL_MESSAGE_ID, 'toolCall');
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $storedDelegateCall['status']);
        self::assertSame(['task' => 'look it up'], $storedDelegateCall['arguments']);
        self::assertSame(AIConversationMessage::STATUS_PENDING, $this->findStoredJsonColumn(601, 'toolCall')['status']);
    }

    public function testAChildsResultSliceIsMergedOnlyWhileTheRowCarriesNoSliceOfThatChild(): void
    {
        $this->insertToolCallRow(self::DELEGATE_CALL_MESSAGE_ID, 1, ['name' => DelegateTool::NAME, 'status' => AIConversationMessage::STATUS_RUNNING]);
        $messagesService = $this->getMessagesService();
        $firstChild = $this->buildChildConversation(201);
        $secondChild = $this->buildChildConversation(202);

        self::assertTrue($messagesService->mergeDelegationResultSliceUnlessPresent($firstChild), 'a row without a results document');
        self::assertFalse($messagesService->mergeDelegationResultSliceUnlessPresent($firstChild), 'the slice of that child is there');
        self::assertTrue($messagesService->mergeDelegationResultSliceUnlessPresent($secondChild), 'another child adds its own slice');
        self::assertFalse($messagesService->mergeDelegationResultSliceUnlessPresent($this->buildConversation(203)), 'no delegate call to write to');

        $storedDelegationResults = $this->findStoredJsonColumn(self::DELEGATE_CALL_MESSAGE_ID, 'delegationResults');
        self::assertSame(['201', '202'], array_map('strval', array_keys($storedDelegationResults)));
        self::assertEquals($firstChild->buildDelegationResultSlice()->toStoredSlice(), $storedDelegationResults['201']);
    }

    public function testASubAgentRecordIsWrittenOnceAndItsRunMovesFromQueuedToItsEndExactlyOnce(): void
    {
        $this->insertToolCallRow(self::DELEGATE_CALL_MESSAGE_ID, 1, ['toolCallId' => 'call_d', 'name' => DelegateTool::NAME, 'status' => AIConversationMessage::STATUS_RUNNING]);
        $messagesService = $this->getMessagesService();
        $subAgentRecord = $this->buildSubAgentRecordWithQueuedRun(1, 201);

        self::assertTrue($messagesService->writeSubAgentRecordOfSpawn(self::DELEGATE_CALL_MESSAGE_ID, $subAgentRecord));
        self::assertFalse($messagesService->writeSubAgentRecordOfSpawn(self::DELEGATE_CALL_MESSAGE_ID, $subAgentRecord), 'the row carries a record');

        $startedAt = DateTime::fromString('2026-03-04 05:06:07');
        self::assertFalse($messagesService->writeSubAgentRunStarted(self::DELEGATE_CALL_MESSAGE_ID, 2, $startedAt), 'the record is on run 1');
        self::assertTrue($messagesService->writeSubAgentRunStarted(self::DELEGATE_CALL_MESSAGE_ID, 1, $startedAt));
        self::assertFalse($messagesService->writeSubAgentRunStarted(self::DELEGATE_CALL_MESSAGE_ID, 1, $startedAt), 'a running run does not start again');
        self::assertFalse(
            $messagesService->stampSubAgentRunReportedToParent(self::DELEGATE_CALL_MESSAGE_ID, 1, $startedAt),
            'a run that has not ended is never reported'
        );

        $completedRun = DelegationSubAgentRun::ended(1, DelegationSubAgentRun::STATUS_COMPLETED, DateTime::fromString('2026-03-04 05:07:00'), 'the answer is "42" — ünï', null, null, 0.0125);
        $failedRun = DelegationSubAgentRun::ended(1, DelegationSubAgentRun::STATUS_FAILED, DateTime::fromString('2026-03-04 05:08:00'), null, DelegationSubAgentRun::FAILURE_REASON_CHILD_FAILED, 'late', 0.5);
        self::assertTrue($messagesService->writeSubAgentRunEnded(self::DELEGATE_CALL_MESSAGE_ID, $completedRun));
        self::assertFalse($messagesService->writeSubAgentRunEnded(self::DELEGATE_CALL_MESSAGE_ID, $failedRun), 'an ended run is never overwritten');
        self::assertFalse(
            $messagesService->writeSubAgentRunEnded(self::DELEGATE_CALL_MESSAGE_ID, DelegationSubAgentRun::queued(1, $startedAt)),
            'a run without a recorded end writes nothing'
        );

        $storedRun = $this->findStoredJsonColumn(self::DELEGATE_CALL_MESSAGE_ID, 'delegationResults')['subAgent']['runs']['1'];
        self::assertSame(DelegationSubAgentRun::STATUS_COMPLETED, $storedRun['status']);
        self::assertSame('2026-03-04 05:06:07', $storedRun['startedAt']);
        self::assertSame('2026-03-04 05:07:00', $storedRun['finishedAt']);
        self::assertSame('the answer is "42" — ünï', $storedRun['verdict']);
        self::assertSame(0.0125, $storedRun['costInUsd'], 'a number stays a JSON number');
        self::assertArrayNotHasKey('failureReason', $storedRun);
        self::assertArrayNotHasKey('reportedToParentAt', $storedRun);

        $reportedAt = DateTime::fromString('2026-03-04 05:07:30');
        self::assertTrue($messagesService->stampSubAgentRunReportedToParent(self::DELEGATE_CALL_MESSAGE_ID, 1, $reportedAt));
        self::assertFalse($messagesService->stampSubAgentRunReportedToParent(self::DELEGATE_CALL_MESSAGE_ID, 1, $reportedAt), 'the stamp is written once');
        self::assertSame(
            '2026-03-04 05:07:30',
            $this->findStoredJsonColumn(self::DELEGATE_CALL_MESSAGE_ID, 'delegationResults')['subAgent']['runs']['1']['reportedToParentAt']
        );
    }

    public function testAContinuationRunOpensOnceAfterARecordedEndAndMovesTheRecordToIt(): void
    {
        $this->insertToolCallRow(self::DELEGATE_CALL_MESSAGE_ID, 1, ['toolCallId' => 'call_d', 'name' => DelegateTool::NAME, 'status' => AIConversationMessage::STATUS_COMPLETED]);
        $messagesService = $this->getMessagesService();
        $queuedAt = DateTime::fromString('2026-03-04 06:00:00');
        self::assertTrue($messagesService->writeSubAgentRecordOfSpawn(self::DELEGATE_CALL_MESSAGE_ID, $this->buildSubAgentRecordWithQueuedRun(1, 201)));
        $continuationRun = DelegationSubAgentRun::continuation(2, DelegationSubAgentRun::CONTINUED_BY_PARENT, 'please go on', $queuedAt);

        self::assertFalse($messagesService->openSubAgentContinuationRunRecord(self::DELEGATE_CALL_MESSAGE_ID, $continuationRun), 'run 1 has not ended');
        self::assertTrue($messagesService->writeSubAgentRunStarted(self::DELEGATE_CALL_MESSAGE_ID, 1, $queuedAt));
        self::assertTrue($messagesService->writeSubAgentRunEnded(
            self::DELEGATE_CALL_MESSAGE_ID,
            DelegationSubAgentRun::ended(1, DelegationSubAgentRun::STATUS_TIMED_OUT, $queuedAt, null, DelegationSubAgentRun::FAILURE_REASON_TIMED_OUT, null, null)
        ));
        self::assertTrue($messagesService->openSubAgentContinuationRunRecord(self::DELEGATE_CALL_MESSAGE_ID, $continuationRun));
        self::assertFalse($messagesService->openSubAgentContinuationRunRecord(self::DELEGATE_CALL_MESSAGE_ID, $continuationRun), 'run 2 exists');
        self::assertFalse(
            $messagesService->openSubAgentContinuationRunRecord(
                self::DELEGATE_CALL_MESSAGE_ID,
                DelegationSubAgentRun::continuation(4, DelegationSubAgentRun::CONTINUED_BY_PARENT, 'skips a run', $queuedAt)
            ),
            'the record is not on run 3'
        );

        $storedSubAgentRecord = $this->findStoredJsonColumn(self::DELEGATE_CALL_MESSAGE_ID, 'delegationResults')['subAgent'];
        self::assertSame(2, $storedSubAgentRecord['currentRunNumber'], 'the run number stays a JSON number');
        self::assertSame(DelegationSubAgentRun::STATUS_TIMED_OUT, $storedSubAgentRecord['runs']['1']['status']);
        self::assertSame(DelegationSubAgentRun::STATUS_QUEUED, $storedSubAgentRecord['runs']['2']['status']);
        self::assertSame('please go on', $storedSubAgentRecord['runs']['2']['openingMessage']);
        self::assertSame(2, $storedSubAgentRecord['runs']['2']['runNumber']);
        self::assertTrue($messagesService->writeSubAgentRunStarted(self::DELEGATE_CALL_MESSAGE_ID, 2, $queuedAt), 'the record is on run 2 now');

        $storedDelegateCallRow = $messagesService->findStoredDelegateCallRow(self::DELEGATE_CALL_MESSAGE_ID);
        self::assertSame(self::PARENT_CONVERSATION_ID, $storedDelegateCallRow?->aiConversationId);
        self::assertSame(2, $storedDelegateCallRow->delegationResults->subAgent->currentRunNumber);
        self::assertSame('call_d', $storedDelegateCallRow->toolCall->toolCallId);
        self::assertNull($messagesService->findStoredDelegateCallRow(999));
        $connection = $this->getDatabaseConnection();
        $connection->beginTransaction();
        self::assertSame(self::DELEGATE_CALL_MESSAGE_ID, $messagesService->lockStoredDelegateCallRow(self::DELEGATE_CALL_MESSAGE_ID)?->id);
        $connection->commit();
    }

    public function testTheSubAgentReadsOfAConversationFindItsRecordsItsNumbersAndTheNextNumber(): void
    {
        $messagesService = $this->getMessagesService();
        $parentConversation = $this->buildConversation(self::PARENT_CONVERSATION_ID);
        self::assertSame(1, $messagesService->findNextSubAgentNumber($parentConversation), 'the first sub-agent of a conversation');

        foreach ([501 => 1, 502 => 2, 503 => 5] as $delegateCallMessageId => $subAgentNumber) {
            $this->insertToolCallRow(
                $delegateCallMessageId,
                $delegateCallMessageId - 500,
                ['toolCallId' => 'call_' . $subAgentNumber, 'name' => DelegateTool::NAME, 'status' => AIConversationMessage::STATUS_RUNNING],
                $subAgentNumber === 5 ? ['truncatedAt' => '2026-01-02 03:04:05'] : []
            );
            self::assertTrue($messagesService->writeSubAgentRecordOfSpawn($delegateCallMessageId, $this->buildSubAgentRecordWithQueuedRun($subAgentNumber, 200 + $subAgentNumber)));
        }
        $this->insertToolCallRow(601, 9, ['name' => 'readPage', 'status' => AIConversationMessage::STATUS_COMPLETED]);

        $subAgentRecords = $messagesService->findSubAgentRecordsOfConversation($parentConversation)->getElements();
        self::assertSame([1, 2], [$subAgentRecords[0]->number, $subAgentRecords[1]->number], 'in conversation order, without the truncated row');
        self::assertCount(1, $messagesService->findSubAgentRecordsOfConversation($parentConversation, 1)->getElements());
        self::assertSame(2, $messagesService->findSubAgentRecordsOfConversation($parentConversation, 1)->getElements()[0]->number, 'the newest row');
        self::assertSame(502, $messagesService->findDelegateCallMessageIdOfSubAgentNumber($parentConversation, 2));
        self::assertNull($messagesService->findDelegateCallMessageIdOfSubAgentNumber($parentConversation, 5), 'a truncated row never matches');
        self::assertNull($messagesService->findDelegateCallMessageIdOfSubAgentNumber($parentConversation, 3));
        self::assertSame(6, $messagesService->findNextSubAgentNumber($parentConversation), 'a truncated row keeps its number');
    }

    public function testAWaitingSignalResumesTheConversationUnlessItIsTheRunEndThatDoesNotResume(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation(self::PARENT_CONVERSATION_ID);
        $this->insertSignalRow(701, 4, AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME, AIConversationMessage::STATUS_PENDING);
        $this->insertSignalRow(702, 5, AIConversationMessage::SIGNAL_SOURCE_ORCHESTRATOR_MESSAGE, AIConversationMessage::STATUS_COMPLETED);

        self::assertFalse($messagesService->hasSignalWaitingThatResumesTheConversation($conversation));
        self::assertFalse($messagesService->hasOrchestratorMessageWaitingForAModelCall($conversation));

        $this->insertSignalRow(703, 6, AIConversationMessage::SIGNAL_SOURCE_ORCHESTRATOR_MESSAGE, AIConversationMessage::STATUS_PENDING);

        self::assertTrue($messagesService->hasSignalWaitingThatResumesTheConversation($conversation));
        self::assertTrue($messagesService->hasSignalWaitingThatResumesTheConversation($conversation, 5));
        self::assertFalse($messagesService->hasSignalWaitingThatResumesTheConversation($conversation, 6), 'only rows above the index');
        self::assertTrue($messagesService->hasOrchestratorMessageWaitingForAModelCall($conversation));
        self::assertFalse($messagesService->hasOrchestratorMessageWaitingForAModelCall($this->buildConversation(999)));
    }

    public function testTheSpendOfAConversationSumsEveryRowsCostAndItsToolCallsExecutionCosts(): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 101]);
        $this->insertToolCallRow(601, 1, ['name' => 'searchTheWeb', 'executionCosts' => ['totalCostInUSD' => 0.005, 'bookingCount' => 1]], ['costInUSD' => 0.01]);
        $this->insertToolCallRow(602, 2, ['name' => 'readPage', 'executionCosts' => null], ['costInUSD' => 0.02]);
        $this->insertToolCallRow(603, 3, ['name' => 'readPage']);
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => 604,
            'aiConversationId' => 101,
            'indexInConversation' => 1,
            'message' => 'a row of another conversation',
            'costInUSD' => 9.0,
        ]);
        $messagesService = $this->getMessagesService();

        self::assertSame(0.035, $messagesService->findTotalCostInUsdOfConversation($this->buildConversation(self::PARENT_CONVERSATION_ID)));
        self::assertSame(0.0, $messagesService->findTotalCostInUsdOfConversation($this->buildConversation(999)));
    }

    public function testTheHarvestMarkerIsWrittenOnTheNamedRowsOnly(): void
    {
        $this->insertToolCallRow(601, 1, ['name' => 'readPage']);
        $this->insertToolCallRow(602, 2, ['name' => 'readPage']);
        $this->insertToolCallRow(603, 3, ['name' => 'readPage']);

        $this->getMessagesService()->markMessagesIngestedIntoMemory([601, 603]);
        $this->getMessagesService()->markMessagesIngestedIntoMemory([]);

        $messageTableName = DBAIConversationMessageModel::getTableName();
        self::assertNotNull($this->findStoredRow($messageTableName, 601)['memoryIngestedAt']);
        self::assertNull($this->findStoredRow($messageTableName, 602)['memoryIngestedAt']);
        self::assertNotNull($this->findStoredRow($messageTableName, 603)['memoryIngestedAt']);
    }

    protected function getMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** A conversation object that names the stored row `$conversationId` and nothing else. */
    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        return $conversation;
    }

    /** A delegation child of the test's `delegate` call, with no message of its own loaded. */
    protected function buildChildConversation(int $childConversationId): AIConversation
    {
        $childConversation = $this->buildConversation($childConversationId);
        $childConversation->delegatedFromMessageId = self::DELEGATE_CALL_MESSAGE_ID;
        $childConversation->messages = new AIConversationMessages();
        return $childConversation;
    }

    /** A message object that names the stored row `$messageId` and nothing else. */
    protected function buildMessage(int $messageId): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->id = $messageId;
        return $message;
    }

    /** The record of sub-agent `$subAgentNumber` as the spawn writes it: run 1, queued. */
    protected function buildSubAgentRecordWithQueuedRun(int $subAgentNumber, int $childConversationId): DelegationSubAgentRecord
    {
        return DelegationSubAgentRecord::fromStoredArray([
            'number' => $subAgentNumber,
            'childConversationId' => $childConversationId,
            'currentRunNumber' => 1,
            'runs' => ['1' => ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_QUEUED, 'queuedAt' => '2026-03-04 05:00:00']],
        ]);
    }

    /**
     * A tool-call row of the parent conversation.
     *
     * @param array<string, mixed> $storedToolCall The `toolCall` document of the row
     * @param array<string, mixed> $columnValues Further columns of the row
     */
    protected function insertToolCallRow(int $messageId, int $indexInConversation, array $storedToolCall, array $columnValues = []): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), $columnValues + [
            'id' => $messageId,
            'aiConversationId' => self::PARENT_CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'toolCall' => json_encode($storedToolCall, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    protected function insertSignalRow(int $messageId, int $indexInConversation, string $signalSource, string $status): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => self::PARENT_CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_SIGNAL,
            'role' => AIConversationMessage::ROLE_SYSTEM,
            'message' => 'a signal',
            'signalSource' => $signalSource,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string|int, mixed> The decoded document of a JSON column of the stored message row
     */
    protected function findStoredJsonColumn(int $messageId, string $columnName): array
    {
        $storedMessage = $this->findStoredRow(DBAIConversationMessageModel::getTableName(), $messageId);
        self::assertNotNull($storedMessage, 'message ' . $messageId . ' is stored');
        self::assertIsString($storedMessage[$columnName], 'column ' . $columnName . ' carries a document');
        return json_decode($storedMessage[$columnName], true, 512, JSON_THROW_ON_ERROR);
    }
}
