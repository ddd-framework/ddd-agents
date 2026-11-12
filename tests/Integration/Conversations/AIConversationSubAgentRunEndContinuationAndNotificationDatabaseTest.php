<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationResultsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Infrastructure\Services\DDDService;
use RuntimeException;

/**
 * The three writers around the end of a sub-agent run against the database, on both engines: the run end
 * ({@see AIConversationDelegationResultsService::recordSubAgentRunEnd()} — one READ COMMITTED transaction over the
 * child, the record and the deadline row), the continuation run
 * ({@see AIConversationDelegationSpawnService::openSubAgentContinuationRun()} — a transaction of its own, or a level
 * of the caller's) and the parent's notification
 * ({@see AIConversationDelegationNotificationsService::notifyParentOfSubAgentRunEnd()} — a transaction inside the hold
 * of the parent's message index lock, which on PostgreSQL is itself a transaction).
 */
class AIConversationSubAgentRunEndContinuationAndNotificationDatabaseTest extends DelegationTreeDatabaseTestCase
{
    protected const int CHILD_CONVERSATION_ID = 101;

    protected const int DELEGATE_ROW_ID = 700;

    protected const int DEADLINE_ID = 520;

    protected const string RUN_TOKEN = 'token-101';

    protected DelegationResultsServiceWithRecordingCollaborators $resultsService;

    protected DelegationNotificationsServiceWithRecordedParentResume $notificationsService;

    protected RecordingQueuePromotionSpawnService $spawnService;

    protected DelegationBatchesServiceWithRecordingCollaborators $batchesService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
        $this->resultsService = new DelegationResultsServiceWithRecordingCollaborators();
        $this->notificationsService = new DelegationNotificationsServiceWithRecordedParentResume();
        $this->spawnService = new RecordingQueuePromotionSpawnService();
        $this->batchesService = new DelegationBatchesServiceWithRecordingCollaborators();
    }

    protected function onTearDown(): void
    {
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
        AIConversationMessagesService::forgetClaimFencedWriteStateForNewJob();
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        $connection = $this->getDatabaseConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    public function testTheEndOfARunIsWrittenOnceOnTheChildTheRecordAndTheDeadlineRow(): void
    {
        $this->insertSubAgentAtWork();
        $this->insertChildRow(801, 0, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, 'Write the draft.');
        $this->insertChildRow(802, 1, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, 'The draft is ready.');
        $childConversation = $this->buildChildAsItsWorkerLoadedIt([801 => 'Write the draft.', 802 => 'The draft is ready.']);

        $runEnd = $this->resultsService->recordSubAgentRunEnd(new AgentRun(), $childConversation, self::RUN_TOKEN, AIConversationRunState::COMPLETED, $this->batchesService);

        self::assertTrue($runEnd->runEndWritten);
        self::assertFalse($runEnd->yieldedForAcceptedInput);
        self::assertSame(self::PARENT_CONVERSATION_ID, $runEnd->rootConversationIdWithFreedSlot);
        self::assertSame(self::DELEGATE_ROW_ID, $runEnd->delegateCallMessageId);
        self::assertSame(1, $runEnd->runNumber);
        $storedChild = $this->findStoredConversation(self::CHILD_CONVERSATION_ID);
        self::assertSame(AIConversationRunState::COMPLETED, $storedChild['runState']);
        self::assertNull($storedChild['runToken']);
        $storedRun = $this->findStoredRunOfDelegateRow(self::DELEGATE_ROW_ID, 1);
        self::assertSame(DelegationSubAgentRun::STATUS_COMPLETED, $storedRun['status']);
        self::assertSame('The draft is ready.', $storedRun['verdict']);
        self::assertNotEmpty($storedRun['finishedAt']);
        self::assertArrayNotHasKey('reportedToParentAt', $storedRun, 'the notification is the next writer\'s');
        self::assertNotNull($this->findStoredBatch(self::DEADLINE_ID)['settledAt'], 'the deadline of the run ended with it');
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());

        $redeliveredRunEnd = $this->resultsService->recordSubAgentRunEnd(new AgentRun(), $childConversation, self::RUN_TOKEN, AIConversationRunState::FAILED, $this->batchesService);

        self::assertFalse($redeliveredRunEnd->runEndWritten);
        self::assertFalse($redeliveredRunEnd->yieldedForAcceptedInput);
        self::assertSame(self::PARENT_CONVERSATION_ID, $redeliveredRunEnd->rootConversationIdWithFreedSlot, 'the child has ended: its slot is free');
        self::assertSame(AIConversationRunState::COMPLETED, $this->findStoredConversation(self::CHILD_CONVERSATION_ID)['runState']);
        self::assertSame(DelegationSubAgentRun::STATUS_COMPLETED, $this->findStoredRunOfDelegateRow(self::DELEGATE_ROW_ID, 1)['status'], 'a second end never overwrites the first');
        self::assertSame([], $this->resultsService->resumedConversationIds);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testARunEndGivesWayToAnInputItsWorkerHasNotSeen(): void
    {
        $this->insertSubAgentAtWork();
        $this->insertChildRow(801, 0, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, 'Write the draft.');
        $this->insertChildRow(802, 1, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, 'The draft is ready.');
        $childConversation = $this->buildChildAsItsWorkerLoadedIt([801 => 'Write the draft.', 802 => 'The draft is ready.']);
        // stored after the worker's last look
        $this->insertChildRow(803, 2, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, 'Please make it shorter.');

        $runEnd = $this->resultsService->recordSubAgentRunEnd(new AgentRun(), $childConversation, self::RUN_TOKEN, AIConversationRunState::COMPLETED, $this->batchesService);

        self::assertTrue($runEnd->yieldedForAcceptedInput);
        self::assertFalse($runEnd->runEndWritten);
        $storedChild = $this->findStoredConversation(self::CHILD_CONVERSATION_ID);
        self::assertSame(AIConversationRunState::IDLE, $storedChild['runState'], 'the child is released for its next turn');
        self::assertNull($storedChild['runToken']);
        self::assertNotNull($storedChild['agentTurnOwedAt'], 'the next turn is owed, so a lost resume is repeated');
        self::assertSame(DelegationSubAgentRun::STATUS_RUNNING, $this->findStoredRunOfDelegateRow(self::DELEGATE_ROW_ID, 1)['status']);
        self::assertNull($this->findStoredBatch(self::DEADLINE_ID)['settledAt']);
        self::assertSame([self::CHILD_CONVERSATION_ID], $this->resultsService->resumedConversationIds);
        self::assertSame([AIConversationRealtimeSignalService::STATUS_RUNNING], $this->resultsService->recordingRealtimeSignalService->publishedRunStatuses);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testAMessageOfTheOrchestratorNoModelCallHasReadHoldsBackACompletedEndAndIsNamedInAFailedEnd(): void
    {
        $this->insertSubAgentAtWork();
        $this->insertChildRow(801, 0, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, 'Write the draft.');
        // written while the final model call ran: it sits below the final text and waits for a model call
        $this->insertChildRow(802, 1, AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::ROLE_SYSTEM, 'Use the new title.', [
            'status' => AIConversationMessage::STATUS_PENDING,
            'signalSource' => AIConversationMessage::SIGNAL_SOURCE_ORCHESTRATOR_MESSAGE,
        ]);
        $this->insertChildRow(803, 2, AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, 'The draft is ready.');
        $childConversation = $this->buildChildAsItsWorkerLoadedIt([801 => 'Write the draft.', 803 => 'The draft is ready.']);

        $completedRunEnd = $this->resultsService->recordSubAgentRunEnd(new AgentRun(), $childConversation, self::RUN_TOKEN, AIConversationRunState::COMPLETED, $this->batchesService);

        self::assertTrue($completedRunEnd->yieldedForAcceptedInput, 'the next turn of the child reads the message');
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredConversation(self::CHILD_CONVERSATION_ID)['runState']);
        self::assertSame(DelegationSubAgentRun::STATUS_RUNNING, $this->findStoredRunOfDelegateRow(self::DELEGATE_ROW_ID, 1)['status']);

        // the next turn of the child fails before a model call: the message stays unread
        $this->getDatabaseConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runState` = ?, `runToken` = ? WHERE `id` = ?',
            [AIConversationRunState::RUNNING, self::RUN_TOKEN, self::CHILD_CONVERSATION_ID]
        );
        $failedRunEnd = $this->resultsService->recordSubAgentRunEnd(new AgentRun(), $childConversation, self::RUN_TOKEN, AIConversationRunState::FAILED, $this->batchesService);

        self::assertTrue($failedRunEnd->runEndWritten, 'a failed end is never held back: its next turn could not read the message either');
        self::assertSame(AIConversationRunState::FAILED, $this->findStoredConversation(self::CHILD_CONVERSATION_ID)['runState']);
        $storedRun = $this->findStoredRunOfDelegateRow(self::DELEGATE_ROW_ID, 1);
        self::assertSame(DelegationSubAgentRun::STATUS_FAILED, $storedRun['status']);
        self::assertSame(DelegationSubAgentRun::FAILURE_REASON_CHILD_FAILED, $storedRun['failureReason']);
        self::assertSame(AIConversationDelegationResultsService::UNREAD_MESSAGE_NOTE, $storedRun['failureDetail']);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testAnEndedSubAgentContinuesWithANewRunAndANewDeadlineRow(): void
    {
        $this->insertEndedSubAgent(AIConversationRunState::COMPLETED, true);
        $childConversation = $this->buildChildAsItsWorkerLoadedIt([]);

        self::assertTrue($this->spawnService->openSubAgentContinuationRun($childConversation, 'Please shorten the draft.', DelegationSubAgentRun::CONTINUED_BY_ADMIN));

        $storedChild = $this->findStoredConversation(self::CHILD_CONVERSATION_ID);
        self::assertSame(AIConversationRunState::QUEUED, $storedChild['runState'], 'the child waits for a free slot like every new child');
        $continuationDeadlineId = (int)$storedChild['delegationBatchId'];
        self::assertNotSame(self::DEADLINE_ID, $continuationDeadlineId);
        $storedContinuationDeadline = $this->findStoredBatch($continuationDeadlineId);
        self::assertSame(self::DELEGATE_ROW_ID, (int)$storedContinuationDeadline['spawnedByMessageId']);
        self::assertSame(self::PARENT_CONVERSATION_ID, (int)$storedContinuationDeadline['parentConversationId']);
        self::assertNull($storedContinuationDeadline['settledAt']);
        self::assertSame(2, $this->findStoredSubAgentRecord()['currentRunNumber']);
        $storedContinuationRun = $this->findStoredRunOfDelegateRow(self::DELEGATE_ROW_ID, 2);
        self::assertSame(DelegationSubAgentRun::STATUS_QUEUED, $storedContinuationRun['status']);
        self::assertSame(DelegationSubAgentRun::CONTINUED_BY_ADMIN, $storedContinuationRun['continuedBy']);
        self::assertSame('Please shorten the draft.', $storedContinuationRun['openingMessage']);
        self::assertSame([self::PARENT_CONVERSATION_ID], $this->spawnService->rootConversationIdsOfferedFreedSlots);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());

        self::assertFalse(
            $this->spawnService->openSubAgentContinuationRun($childConversation, 'And once more.', DelegationSubAgentRun::CONTINUED_BY_ADMIN),
            'run 2 has not ended'
        );
        self::assertSame(2, $this->countDeadlineRowsOfTheDelegateRow());
        self::assertSame(2, $this->findStoredSubAgentRecord()['currentRunNumber']);
    }

    public function testAContinuationThatCannotQueueTheChildAgainLeavesNoDeadlineRowBehind(): void
    {
        // the record says the run ended while a worker still drives the child
        $this->insertEndedSubAgent(AIConversationRunState::RUNNING, true);
        $childConversation = $this->buildChildAsItsWorkerLoadedIt([]);

        self::assertFalse($this->spawnService->openSubAgentContinuationRun($childConversation, 'Please shorten the draft.', DelegationSubAgentRun::CONTINUED_BY_ADMIN));

        self::assertSame(1, $this->countDeadlineRowsOfTheDelegateRow(), 'the deadline row of the run that was not opened is rolled back');
        self::assertSame(1, $this->findStoredSubAgentRecord()['currentRunNumber']);
        $storedChild = $this->findStoredConversation(self::CHILD_CONVERSATION_ID);
        self::assertSame(AIConversationRunState::RUNNING, $storedChild['runState']);
        self::assertSame(self::DEADLINE_ID, (int)$storedChild['delegationBatchId']);
        self::assertSame([], $this->spawnService->rootConversationIdsOfferedFreedSlots);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testAContinuationInsideATransactionOfTheCallerIsStoredOnlyWhenThatTransactionCommits(): void
    {
        $this->insertEndedSubAgent(AIConversationRunState::COMPLETED, true);
        $childConversation = $this->buildChildAsItsWorkerLoadedIt([]);
        $connection = $this->getDatabaseConnection();
        $connection->beginTransaction();

        self::assertTrue($this->spawnService->openSubAgentContinuationRun($childConversation, 'Please shorten the draft.', DelegationSubAgentRun::CONTINUED_BY_ADMIN));

        self::assertSame(1, $connection->getTransactionNestingLevel(), 'the continuation ended its own level only');
        self::assertSame(2, $this->findStoredSubAgentRecord()['currentRunNumber']);
        $connection->rollBack();
        self::assertSame(1, $this->findStoredSubAgentRecord()['currentRunNumber']);
        self::assertSame(AIConversationRunState::COMPLETED, $this->findStoredConversation(self::CHILD_CONVERSATION_ID)['runState']);
        self::assertSame(1, $this->countDeadlineRowsOfTheDelegateRow());
    }

    public function testTheParentIsToldOnceAboutAnEndedRunAndResumed(): void
    {
        $this->insertEndedSubAgent(AIConversationRunState::COMPLETED, false);

        self::assertTrue($this->notificationsService->notifyParentOfSubAgentRunEnd(self::DELEGATE_ROW_ID, 1));

        $storedNotificationRows = $this->findStoredSignalRowsOfTheParent();
        self::assertCount(1, $storedNotificationRows);
        self::assertSame(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED, $storedNotificationRows[0]['signalSource']);
        self::assertSame(AIConversationMessage::STATUS_PENDING, $storedNotificationRows[0]['status'], 'the row waits for a model call of the parent');
        self::assertSame(1, (int)$storedNotificationRows[0]['indexInConversation'], 'above the delegate row');
        self::assertStringContainsString('The draft is ready.', (string)$storedNotificationRows[0]['message']);
        self::assertNotEmpty($this->findStoredRunOfDelegateRow(self::DELEGATE_ROW_ID, 1)['reportedToParentAt']);
        self::assertSame([self::PARENT_CONVERSATION_ID], $this->notificationsService->resumedParentConversationIds);
        self::assertSame([AIConversationRealtimeSignalService::STATUS_RUNNING], $this->notificationsService->recordingRealtimeSignalService->publishedRunStatuses);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive(), 'the hold of the index lock ended its transaction');

        self::assertFalse($this->notificationsService->notifyParentOfSubAgentRunEnd(self::DELEGATE_ROW_ID, 1), 'the run was reported');
        self::assertCount(1, $this->findStoredSignalRowsOfTheParent());
        self::assertSame([self::PARENT_CONVERSATION_ID], $this->notificationsService->resumedParentConversationIds);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testAParentThatHasEndedAndASubAgentThatReportsWithoutAResumeGetTheirRowAndNoResume(): void
    {
        $this->insertEndedSubAgent(AIConversationRunState::COMPLETED, false);
        $storedRecordThatReportsWithoutAResume = $this->buildStoredSubAgentRecord(102, 1, [
            1 => ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_COMPLETED, 'finishedAt' => $this->formatMomentSecondsAgo(30), 'verdict' => 'The picture is stored.'],
        ]);
        $storedRecordThatReportsWithoutAResume['subAgent']['number'] = 2;
        $storedRecordThatReportsWithoutAResume['subAgent']['label'] = 'illustrator';
        $storedRecordThatReportsWithoutAResume['subAgent']['notifiesParentWithoutResuming'] = true;
        $this->insertDelegateRow(701, 1, AIConversationMessage::STATUS_RUNNING, $storedRecordThatReportsWithoutAResume);

        self::assertTrue($this->notificationsService->notifyParentOfSubAgentRunEnd(701, 1));

        $storedNotificationRows = $this->findStoredSignalRowsOfTheParent();
        self::assertCount(1, $storedNotificationRows);
        self::assertSame(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME, $storedNotificationRows[0]['signalSource']);
        self::assertSame([], $this->notificationsService->resumedParentConversationIds, 'the row waits for the next turn of the parent');

        $this->getDatabaseConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runState` = ? WHERE `id` = ?',
            [AIConversationRunState::COMPLETED, self::PARENT_CONVERSATION_ID]
        );
        self::assertTrue($this->notificationsService->notifyParentOfSubAgentRunEnd(self::DELEGATE_ROW_ID, 1));

        self::assertCount(2, $this->findStoredSignalRowsOfTheParent());
        self::assertSame([], $this->notificationsService->resumedParentConversationIds, 'a parent that has ended is never resumed');
        self::assertSame([], $this->notificationsService->recordingRealtimeSignalService->publishedRunStatuses);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testANotificationThatFailsAfterItsRowWasWrittenStoresNothingAndGivesTheLockBack(): void
    {
        $this->insertEndedSubAgent(AIConversationRunState::COMPLETED, false);
        $this->notificationsService->failsAfterTheNotificationRowWasWritten = true;

        try {
            $this->notificationsService->notifyParentOfSubAgentRunEnd(self::DELEGATE_ROW_ID, 1);
            self::fail('the failed write must reach the caller');
        } catch (RuntimeException) {
        }

        self::assertCount(0, $this->findStoredSignalRowsOfTheParent());
        self::assertArrayNotHasKey('reportedToParentAt', $this->findStoredRunOfDelegateRow(self::DELEGATE_ROW_ID, 1), 'the run stays unreported, so the recovery tells the parent');
        self::assertSame([], $this->notificationsService->resumedParentConversationIds);
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());

        $this->notificationsService->failsAfterTheNotificationRowWasWritten = false;
        self::assertTrue($this->notificationsService->notifyParentOfSubAgentRunEnd(self::DELEGATE_ROW_ID, 1), 'the index lock of the parent is free again');
        self::assertCount(1, $this->findStoredSignalRowsOfTheParent());
    }

    /** The parent, its `delegate` row with run 1 at work, the deadline row of that run and the running child. */
    protected function insertSubAgentAtWork(): void
    {
        $this->insertParentConversation();
        $this->insertDelegateRow(self::DELEGATE_ROW_ID, 0, AIConversationMessage::STATUS_RUNNING, $this->buildStoredSubAgentRecord(self::CHILD_CONVERSATION_ID, 1, [
            1 => ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_RUNNING, 'startedAt' => $this->formatMomentSecondsAgo(60)],
        ]));
        $this->insertBatch(['id' => self::DEADLINE_ID, 'spawnedByMessageId' => self::DELEGATE_ROW_ID]);
        $this->insertConversationOfThisEnvironment([
            'id' => self::CHILD_CONVERSATION_ID,
            'runState' => AIConversationRunState::RUNNING,
            'runToken' => self::RUN_TOKEN,
            'delegationBatchId' => self::DEADLINE_ID,
            'delegatedFromMessageId' => self::DELEGATE_ROW_ID,
            'rootConversationId' => self::PARENT_CONVERSATION_ID,
        ]);
    }

    /**
     * The parent, its `delegate` row whose run 1 ended COMPLETED half a minute ago (reported to the parent or not),
     * the ended deadline row of that run and the child in `$childRunState`.
     */
    protected function insertEndedSubAgent(string $childRunState, bool $runWasReportedToTheParent): void
    {
        $endedAt = $this->formatMomentSecondsAgo(30);
        $storedRun = ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_COMPLETED, 'finishedAt' => $endedAt, 'verdict' => 'The draft is ready.'];
        if ($runWasReportedToTheParent) {
            $storedRun['reportedToParentAt'] = $endedAt;
        }
        $this->insertParentConversation();
        $this->insertDelegateRow(self::DELEGATE_ROW_ID, 0, AIConversationMessage::STATUS_RUNNING, $this->buildStoredSubAgentRecord(self::CHILD_CONVERSATION_ID, 1, [1 => $storedRun]));
        $this->insertBatch(['id' => self::DEADLINE_ID, 'spawnedByMessageId' => self::DELEGATE_ROW_ID, 'settledAt' => $endedAt, 'foldedAt' => $endedAt]);
        $this->insertConversationOfThisEnvironment([
            'id' => self::CHILD_CONVERSATION_ID,
            'runState' => $childRunState,
            'runToken' => $childRunState === AIConversationRunState::RUNNING ? self::RUN_TOKEN : null,
            'delegationBatchId' => self::DEADLINE_ID,
            'delegatedFromMessageId' => self::DELEGATE_ROW_ID,
            'rootConversationId' => self::PARENT_CONVERSATION_ID,
        ]);
    }

    /**
     * A stored row of the child conversation.
     *
     * @param array<string, mixed> $furtherColumnValues
     */
    protected function insertChildRow(int $messageId, int $indexInConversation, string $messageType, string $role, string $text, array $furtherColumnValues = []): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), $furtherColumnValues + [
            'id' => $messageId,
            'aiConversationId' => self::CHILD_CONVERSATION_ID,
            'indexInConversation' => $indexInConversation,
            'messageType' => $messageType,
            'role' => $role,
            'authorType' => self::getAuthorTypeOfRole($role),
            'message' => $text,
        ]);
    }

    /** Who wrote a row of `$role`: the owner a user row, the child's model an assistant row, the platform every other. */
    protected static function getAuthorTypeOfRole(string $role): string
    {
        if ($role === AIConversationMessage::ROLE_USER) {
            return AIConversationMessage::AUTHOR_USER;
        }
        return $role === AIConversationMessage::ROLE_ASSISTANT ? AIConversationMessage::AUTHOR_ASSISTANT : AIConversationMessage::AUTHOR_AGENT;
    }

    /**
     * The child as the worker that drives it holds it: the columns that tie it to its parent and the text rows the
     * worker loaded — the first is the task (a user row), every later one a text of the child's model.
     *
     * @param array<int, string> $loadedTextByMessageId
     */
    protected function buildChildAsItsWorkerLoadedIt(array $loadedTextByMessageId): AIConversation
    {
        $childConversation = $this->buildConversation(self::CHILD_CONVERSATION_ID);
        $childConversation->delegatedFromMessageId = self::DELEGATE_ROW_ID;
        $childConversation->delegationBatchId = self::DEADLINE_ID;
        $childConversation->rootConversationId = self::PARENT_CONVERSATION_ID;
        $childConversation->messages = new AIConversationMessages();
        $indexInConversation = 0;
        foreach ($loadedTextByMessageId as $messageId => $loadedText) {
            $loadedMessage = new AIConversationMessage();
            $loadedMessage->id = $messageId;
            $loadedMessage->indexInConversation = $indexInConversation;
            $loadedMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
            $loadedMessage->role = $indexInConversation === 0 ? AIConversationMessage::ROLE_USER : AIConversationMessage::ROLE_ASSISTANT;
            $loadedMessage->authorType = self::getAuthorTypeOfRole($loadedMessage->role);
            $loadedMessage->message = $loadedText;
            $childConversation->messages->add($loadedMessage);
            $indexInConversation++;
        }
        return $childConversation;
    }

    /** @return array<string, mixed> The sub-agent record stored on the `delegate` row of the test */
    protected function findStoredSubAgentRecord(): array
    {
        $storedDelegateRow = $this->findStoredRow(DBAIConversationMessageModel::getTableName(), self::DELEGATE_ROW_ID);
        self::assertNotNull($storedDelegateRow);
        return json_decode((string)$storedDelegateRow['delegationResults'], true)['subAgent'];
    }

    /** How many deadline rows the `delegate` row of the test has — one per run that was opened with a deadline. */
    protected function countDeadlineRowsOfTheDelegateRow(): int
    {
        return (int)$this->getDatabaseConnection()->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '` WHERE `spawnedByMessageId` = ?',
            [self::DELEGATE_ROW_ID]
        );
    }

    /** @return array<int, array<string, mixed>> The signal rows stored in the parent conversation, oldest first */
    protected function findStoredSignalRowsOfTheParent(): array
    {
        return $this->getDatabaseConnection()->fetchAllAssociative(
            'SELECT * FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `aiConversationId` = ? AND `messageType` = ? ORDER BY `id` ASC',
            [self::PARENT_CONVERSATION_ID, AIConversationMessage::MESSAGE_SIGNAL]
        );
    }
}
