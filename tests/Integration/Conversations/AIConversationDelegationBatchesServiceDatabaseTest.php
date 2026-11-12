<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationResumeClaim;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationResumeCondition;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;

/**
 * The raw statements of {@see AIConversationDelegationBatchesService} against the database, on both engines: the end
 * of a child inside its batch (the terminal state written once, the parent resumed once), the readers of a parent's
 * batches, the deadline rows of sub-agent runs (ended and not reported, all runs of one sub-agent, the record of a
 * row), a run and a legacy batch past the deadline, and the cancel of runs that have not started.
 */
class AIConversationDelegationBatchesServiceDatabaseTest extends DelegationTreeDatabaseTestCase
{
    protected const string OTHER_ENVIRONMENT = 'another-workspace';

    protected DelegationBatchesServiceWithRecordingCollaborators $batchesService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
        $this->batchesService = new DelegationBatchesServiceWithRecordingCollaborators();
    }

    protected function onTearDown(): void
    {
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testAChildEndIsWrittenOnceAndTheLastChildOfABatchThatWaitsForAllResumesTheParent(): void
    {
        $this->insertParentConversation();
        $this->insertBatch(['id' => 500, 'expectedDelegationCount' => 2]);
        foreach ([101, 102, 103, 104, 105] as $childConversationId) {
            $this->insertRunningChild($childConversationId, 500);
        }

        $firstEnd = $this->batchesService->claimParentResumeOnChildTerminal($this->buildChild(101, 500), 'token-101', AIConversationRunState::COMPLETED);
        self::assertTrue($firstEnd->childTerminalWon);
        self::assertFalse($firstEnd->wonParentResume, 'one of two expected children has ended');
        self::assertSame(AIConversationRunState::COMPLETED, $this->findStoredConversation(101)['runState']);
        self::assertNull($this->findStoredConversation(101)['runToken']);
        self::assertNull($this->findStoredBatch(500)['settledAt']);

        $redeliveredEnd = $this->batchesService->claimParentResumeOnChildTerminal($this->buildChild(101, 500), 'token-101', AIConversationRunState::FAILED);
        self::assertFalse($redeliveredEnd->childTerminalWon);
        self::assertTrue($redeliveredEnd->childWasAlreadyTerminal);
        self::assertFalse($redeliveredEnd->yieldedForAcceptedInput);
        self::assertSame(AIConversationRunState::COMPLETED, $this->findStoredConversation(101)['runState'], 'a second end never overwrites the first');

        $endWithAnotherToken = $this->batchesService->claimParentResumeOnChildTerminal($this->buildChild(105, 500), 'token-of-another-worker', AIConversationRunState::COMPLETED);
        self::assertFalse($endWithAnotherToken->childTerminalWon);
        self::assertFalse($endWithAnotherToken->childWasAlreadyTerminal);
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredConversation(105)['runState']);

        $lastExpectedEnd = $this->batchesService->claimParentResumeOnChildTerminal($this->buildChild(102, 500), 'token-102', AIConversationRunState::FAILED);
        self::assertTrue($lastExpectedEnd->childTerminalWon);
        self::assertTrue($lastExpectedEnd->wonParentResume, 'a failed child counts as ended for a batch that waits for all');
        self::assertTrue($lastExpectedEnd->wasFreshSettle);
        self::assertNotNull($this->findStoredBatch(500)['settledAt']);

        // the parent has read the results; a child that completes afterwards asks for another read
        $this->batchesService->markChildResultsReadByParent(500);
        self::assertNotNull($this->findStoredBatch(500)['foldedAt']);
        $lateCompletedEnd = $this->batchesService->claimParentResumeOnChildTerminal($this->buildChild(103, 500), 'token-103', AIConversationRunState::COMPLETED);
        self::assertTrue($lateCompletedEnd->childTerminalWon);
        self::assertTrue($lateCompletedEnd->wonParentResume);
        self::assertFalse($lateCompletedEnd->wasFreshSettle);
        self::assertNull($this->findStoredBatch(500)['foldedAt']);

        $this->batchesService->markChildResultsReadByParent(500);
        $lateFailedEnd = $this->batchesService->claimParentResumeOnChildTerminal($this->buildChild(104, 500), 'token-104', AIConversationRunState::FAILED);
        self::assertTrue($lateFailedEnd->childTerminalWon);
        self::assertFalse($lateFailedEnd->wonParentResume, 'a late failure does not resume the parent');
        self::assertNotNull($this->findStoredBatch(500)['foldedAt']);

        $childWithoutBatch = new AIConversation();
        $childWithoutBatch->id = 105;
        self::assertFalse($this->batchesService->claimParentResumeOnChildTerminal($childWithoutBatch, 'token-105', AIConversationRunState::COMPLETED)->childTerminalWon);
    }

    public function testABatchWithAThresholdResumesTheParentWhenEnoughChildrenCompletedOrTheThresholdIsOutOfReach(): void
    {
        $this->insertParentConversation();
        $thresholdOfTwo = ['expectedDelegationCount' => 3, 'delegateWakeCondition' => AIConversationDelegationResumeCondition::QUORUM, 'quorumSuccessThreshold' => 2];
        $this->insertBatch(['id' => 501] + $thresholdOfTwo);
        $this->insertBatch(['id' => 502] + $thresholdOfTwo);
        $this->insertBatch(['id' => 503, 'expectedDelegationCount' => 2, 'delegateWakeCondition' => AIConversationDelegationResumeCondition::ANY]);
        foreach ([[111, 501], [112, 501], [121, 502], [122, 502], [131, 503], [132, 503]] as [$childConversationId, $batchId]) {
            $this->insertRunningChild($childConversationId, $batchId);
        }

        self::assertFalse($this->endChild(111, 501, AIConversationRunState::COMPLETED)->wonParentResume);
        self::assertTrue($this->endChild(112, 501, AIConversationRunState::COMPLETED)->wonParentResume, 'two of three completed reach the threshold of two');

        self::assertFalse($this->endChild(121, 502, AIConversationRunState::FAILED)->wonParentResume, 'two children can still complete');
        self::assertTrue($this->endChild(122, 502, AIConversationRunState::FAILED)->wonParentResume, 'one child left cannot reach the threshold of two');

        self::assertFalse($this->endChild(131, 503, AIConversationRunState::FAILED)->wonParentResume, 'the other child can still complete');
        self::assertTrue($this->endChild(132, 503, AIConversationRunState::COMPLETED)->wonParentResume, 'the first completed child resumes the parent');
    }

    public function testAnInputAcceptedAfterTheLastLookKeepsTheChildRunning(): void
    {
        $this->insertParentConversation();
        $this->insertBatch(['id' => 500]);
        $this->insertRunningChild(101, 500);
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => 900,
            'aiConversationId' => 101,
            'indexInConversation' => 0,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'role' => AIConversationMessage::ROLE_USER,
            'message' => 'one more thing',
        ]);

        $endBeforeTheInputWasSeen = $this->batchesService->claimParentResumeOnChildTerminal($this->buildChild(101, 500), 'token-101', AIConversationRunState::COMPLETED, 899);
        self::assertFalse($endBeforeTheInputWasSeen->childTerminalWon);
        self::assertTrue($endBeforeTheInputWasSeen->yieldedForAcceptedInput);
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredConversation(101)['runState']);
        self::assertSame('token-101', $this->findStoredConversation(101)['runToken']);

        $endAfterTheInputWasSeen = $this->batchesService->claimParentResumeOnChildTerminal($this->buildChild(101, 500), 'token-101', AIConversationRunState::COMPLETED, 900);
        self::assertTrue($endAfterTheInputWasSeen->childTerminalWon);
        self::assertTrue($endAfterTheInputWasSeen->wonParentResume);
    }

    public function testTheBatchesOfAParentAreReadByDeadlineByUnreadResultsAndByTheStateOfTheirChildren(): void
    {
        $this->insertParentConversation();
        $this->insertConversation(['id' => 110, 'runState' => AIConversationRunState::IDLE, 'agentWorkingState' => json_encode(['environment' => self::OTHER_ENVIRONMENT])]);
        $this->insertConversationOfThisEnvironment(['id' => 120, 'runState' => AIConversationRunState::RUNNING]);
        $this->insertConversationOfThisEnvironment(['id' => 130, 'runState' => AIConversationRunState::IDLE]);
        $endedTenMinutesAgo = $this->formatMomentSecondsAgo(600);
        $this->insertBatch(['id' => 500, 'deadlineAt' => $this->formatMomentSecondsAgo(60)]);
        $this->insertBatch(['id' => 501, 'parentConversationId' => 110, 'deadlineAt' => $this->formatMomentSecondsAgo(60)]);
        $this->insertBatch(['id' => 502]);
        $this->insertBatch(['id' => 503, 'settledAt' => $endedTenMinutesAgo]);
        $this->insertBatch(['id' => 504, 'settledAt' => $endedTenMinutesAgo, 'delegateWakeCondition' => AIConversationDelegationResumeCondition::BACKGROUND]);
        $this->insertBatch(['id' => 505, 'parentConversationId' => 120, 'settledAt' => $endedTenMinutesAgo]);
        $this->insertBatch(['id' => 506, 'settledAt' => $endedTenMinutesAgo, 'foldedAt' => $endedTenMinutesAgo]);
        $this->insertBatch(['id' => 507, 'parentConversationId' => 130, 'delegateWakeCondition' => AIConversationDelegationResumeCondition::BACKGROUND]);
        $runStateByChildConversationId = [
            201 => AIConversationRunState::RUNNING,
            202 => AIConversationRunState::QUEUED,
            203 => AIConversationRunState::COMPLETED,
            204 => AIConversationRunState::FAILED,
            205 => AIConversationRunState::IDLE,
        ];
        foreach ($runStateByChildConversationId as $childConversationId => $runState) {
            $this->insertConversationOfThisEnvironment(['id' => $childConversationId, 'runState' => $runState, 'delegationBatchId' => 500]);
        }
        $this->insertConversationOfThisEnvironment(['id' => 206, 'runState' => AIConversationRunState::CANCELLED, 'delegationBatchId' => 502]);

        self::assertSame([500], $this->batchesService->findTimedOutUnfinishedBatchIds(), 'past the deadline, not ended, of a parent of this environment');
        self::assertSame([self::PARENT_CONVERSATION_ID], $this->batchesService->findParentConversationIdsWithUnreadChildResults());
        self::assertSame(503, $this->batchesService->findOldestBatchIdWithUnreadChildResultsForParent(self::PARENT_CONVERSATION_ID));
        self::assertNull($this->batchesService->findOldestBatchIdWithUnreadChildResultsForParent(999));
        self::assertSame([504], $this->batchesService->findSettledUnfoldedBackgroundBatchIdsForParent(self::PARENT_CONVERSATION_ID));
        self::assertTrue($this->batchesService->hasUnfinishedBatchForParent(self::PARENT_CONVERSATION_ID));
        self::assertFalse($this->batchesService->hasUnfinishedBatchForParent(120));
        self::assertFalse($this->batchesService->hasUnfinishedBatchForParent(130), 'background work never keeps its parent waiting');

        $this->batchesService->markChildResultsReadByParent(503);
        self::assertNull($this->batchesService->findOldestBatchIdWithUnreadChildResultsForParent(self::PARENT_CONVERSATION_ID));
        self::assertSame([], $this->batchesService->findParentConversationIdsWithUnreadChildResults());

        $backgroundBatch = $this->batchesService->findBatchRowById(504);
        self::assertInstanceOf(AIConversationDelegationBatch::class, $backgroundBatch);
        self::assertSame(self::PARENT_CONVERSATION_ID, $backgroundBatch->parentConversationId);
        self::assertSame(AIConversationDelegationResumeCondition::BACKGROUND, $backgroundBatch->delegateWakeCondition);
        self::assertNull($this->batchesService->findBatchRowById(999));

        self::assertSame([201, 202], $this->getSortedIds($this->batchesService->findActiveMemberConversationIds(500)));
        $childCountsByBatchId = $this->batchesService->countChildrenByRunStateForBatches([500, 502, 506]);
        self::assertSame([500, 502, 506], array_keys($childCountsByBatchId));
        self::assertSame(2, $childCountsByBatchId[500]->countTerminal());
        self::assertSame(3, $childCountsByBatchId[500]->countExecutable());
        self::assertSame(1, $childCountsByBatchId[502]->countCancelled());
        self::assertSame(0, $childCountsByBatchId[506]->countTerminal());
        self::assertSame([], $this->batchesService->countChildrenByRunStateForBatches([]));
        $endedChildCounts = $this->batchesService->countChildrenByTerminalState(500);
        self::assertSame(1, $endedChildCounts->countCompleted());
        self::assertSame(1, $endedChildCounts->countFailed());
        self::assertSame(0, $endedChildCounts->countCancelled());
    }

    public function testDeadlineRowsOfSubAgentRunsAreReadThroughTheRecordOnTheirDelegateRow(): void
    {
        $this->insertParentConversation();
        $this->insertConversationOfThisEnvironment(['id' => 120, 'runState' => AIConversationRunState::IDLE]);
        $endedTenMinutesAgo = $this->formatMomentSecondsAgo(600);
        $this->insertDelegateRow(700, 0, AIConversationMessage::STATUS_RUNNING, $this->buildStoredSubAgentRecord(101, 2, [
            1 => ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_COMPLETED, 'finishedAt' => $endedTenMinutesAgo, 'reportedToParentAt' => $endedTenMinutesAgo],
            2 => ['runNumber' => 2, 'status' => DelegationSubAgentRun::STATUS_COMPLETED, 'finishedAt' => $endedTenMinutesAgo],
        ]));
        // a row written before a sub-agent had a record: a map of verdicts by child
        $this->insertDelegateRow(701, 1, AIConversationMessage::STATUS_COMPLETED, ['102' => 'done']);
        $this->insertDelegateRow(702, 2, AIConversationMessage::STATUS_COMPLETED, $this->buildStoredSubAgentRecord(103, 1, [
            1 => ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_COMPLETED, 'finishedAt' => $endedTenMinutesAgo],
        ]));
        $this->insertBatch(['id' => 510, 'spawnedByMessageId' => 700, 'settledAt' => $endedTenMinutesAgo, 'foldedAt' => $endedTenMinutesAgo]);
        $this->insertBatch(['id' => 511, 'spawnedByMessageId' => 700, 'settledAt' => $endedTenMinutesAgo, 'foldedAt' => $endedTenMinutesAgo]);
        $this->insertBatch(['id' => 512, 'spawnedByMessageId' => 701, 'settledAt' => $endedTenMinutesAgo]);
        // the run of row 702 ended a moment ago (the notifier may still be at work) and, on another row, two days ago
        $this->insertBatch(['id' => 513, 'spawnedByMessageId' => 702, 'settledAt' => $this->formatMomentSecondsAgo(10), 'foldedAt' => $endedTenMinutesAgo]);
        $this->insertBatch(['id' => 514, 'spawnedByMessageId' => 702, 'settledAt' => $this->formatMomentSecondsAgo(2 * 86400), 'foldedAt' => $endedTenMinutesAgo]);
        $this->insertConversationOfThisEnvironment(['id' => 101, 'runState' => AIConversationRunState::COMPLETED, 'delegationBatchId' => 511, 'delegatedFromMessageId' => 700]);

        self::assertSame([510, 511], $this->batchesService->findEndedUnreportedSubAgentRunDeadlineIds(), 'run 2 has ended and was never reported');
        self::assertSame([510, 511], $this->batchesService->findEndedUnreportedSubAgentRunDeadlineIds($this->buildConversation(self::PARENT_CONVERSATION_ID)));
        self::assertSame([], $this->batchesService->findEndedUnreportedSubAgentRunDeadlineIds($this->buildConversation(120)));
        self::assertSame([], $this->batchesService->findEndedUnreportedSubAgentRunDeadlineIds(new AIConversation()));

        self::assertSame([510, 511], $this->batchesService->findDeadlineIdsOfAllRunsOfTheSameSubAgents([510]));
        self::assertSame([512], $this->batchesService->findDeadlineIdsOfAllRunsOfTheSameSubAgents([512]), 'a legacy batch stays as given');
        self::assertSame([], $this->batchesService->findDeadlineIdsOfAllRunsOfTheSameSubAgents([]));

        $subAgentRecordByDeadlineId = $this->batchesService->findSubAgentRecordsOfDeadlines([510, 512]);
        self::assertSame([510], array_keys($subAgentRecordByDeadlineId));
        self::assertSame(2, $subAgentRecordByDeadlineId[510]->currentRunNumber);
        self::assertSame(101, $subAgentRecordByDeadlineId[510]->childConversationId);
        self::assertSame(2, $subAgentRecordByDeadlineId[510]->runs->countRuns());
        self::assertSame([], $this->batchesService->findSubAgentRecordsOfDeadlines([]));

        self::assertSame(700, $this->batchesService->findSubAgentDelegateCallRowOfDeadline(510)?->id);
        self::assertNull($this->batchesService->findSubAgentDelegateCallRowOfDeadline(512));
        self::assertNull($this->batchesService->findSubAgentDelegateCallRowOfDeadline(999));

        self::assertSame([700], $this->batchesService->findUnresolvedDelegateCallMessageIdsWithSettledBatch(self::PARENT_CONVERSATION_ID));

        self::assertFalse($this->batchesService->hasUnendedSubAgentRunOfParent(self::PARENT_CONVERSATION_ID));
        $this->insertBatch(['id' => 515, 'spawnedByMessageId' => 701]);
        self::assertFalse($this->batchesService->hasUnendedSubAgentRunOfParent(self::PARENT_CONVERSATION_ID), 'a legacy batch is no sub-agent run');
        $this->insertBatch(['id' => 516, 'spawnedByMessageId' => 700]);
        self::assertTrue($this->batchesService->hasUnendedSubAgentRunOfParent(self::PARENT_CONVERSATION_ID));

        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        self::assertTrue($aIConversationMessagesService->stampSubAgentRunReportedToParent(700, 2, new DateTime()));
        self::assertSame([], $this->batchesService->findEndedUnreportedSubAgentRunDeadlineIds());
    }

    public function testASubAgentRunPastItsDeadlineEndsTimedOutUnlessItsChildIsAliveOrStillWaitsForASlot(): void
    {
        $this->insertParentConversation();
        $queuedAt = $this->formatMomentSecondsAgo(7200);
        $runningRun = [1 => ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_RUNNING, 'queuedAt' => $queuedAt, 'startedAt' => $queuedAt]];
        $queuedRun = [1 => ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_QUEUED, 'queuedAt' => $queuedAt]];
        $pastDeadline = ['deadlineAt' => $this->formatMomentSecondsAgo(60), 'created' => $queuedAt];
        $staleHeartbeat = ['runState' => AIConversationRunState::RUNNING, 'runHeartbeatAt' => $this->formatMomentSecondsAgo(86400)];

        // 520: the child stopped beating long ago
        $this->insertDelegateRow(700, 0, AIConversationMessage::STATUS_RUNNING, $this->buildStoredSubAgentRecord(101, 1, $runningRun));
        $this->insertBatch(['id' => 520, 'spawnedByMessageId' => 700] + $pastDeadline);
        $this->insertConversationOfThisEnvironment(['id' => 101, 'runToken' => 'token-101', 'delegationBatchId' => 520] + $staleHeartbeat);
        // 521: the child beat a moment ago
        $this->insertDelegateRow(701, 1, AIConversationMessage::STATUS_RUNNING, $this->buildStoredSubAgentRecord(102, 1, $runningRun));
        $this->insertBatch(['id' => 521, 'spawnedByMessageId' => 701] + $pastDeadline);
        $this->insertConversationOfThisEnvironment(['id' => 102, 'runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-102', 'runHeartbeatAt' => $this->formatMomentSecondsAgo(5), 'delegationBatchId' => 521]);
        // 522: the child waits for a slot, the deadline row was written a moment ago
        $this->insertDelegateRow(702, 2, AIConversationMessage::STATUS_RUNNING, $this->buildStoredSubAgentRecord(103, 1, $queuedRun));
        $this->insertBatch(['id' => 522, 'spawnedByMessageId' => 702, 'deadlineAt' => $this->formatMomentSecondsAgo(60), 'created' => $this->formatMomentSecondsAgo(120)]);
        $this->insertConversationOfThisEnvironment(['id' => 103, 'runState' => AIConversationRunState::QUEUED, 'delegationBatchId' => 522]);
        // 523: the child has waited for a slot longer than a queued child may
        $this->insertDelegateRow(703, 3, AIConversationMessage::STATUS_RUNNING, $this->buildStoredSubAgentRecord(104, 1, $queuedRun));
        $this->insertBatch(['id' => 523, 'spawnedByMessageId' => 703] + $pastDeadline);
        $this->insertConversationOfThisEnvironment(['id' => 104, 'runState' => AIConversationRunState::QUEUED, 'delegationBatchId' => 523]);

        self::assertNull($this->batchesService->timeoutBatch(520), 'the end of a sub-agent run reaches the parent as a notification');
        self::assertSame(AIConversationRunState::FAILED, $this->findStoredConversation(101)['runState']);
        self::assertNull($this->findStoredConversation(101)['runToken']);
        $timedOutRun = $this->findStoredRunOfDelegateRow(700, 1);
        self::assertSame(DelegationSubAgentRun::STATUS_TIMED_OUT, $timedOutRun['status']);
        self::assertSame(DelegationSubAgentRun::FAILURE_REASON_TIMED_OUT, $timedOutRun['failureReason']);
        self::assertStringStartsWith('no step before the deadline of', $timedOutRun['failureDetail']);
        self::assertNotNull($this->findStoredBatch(520)['settledAt']);
        self::assertNotNull($this->findStoredBatch(520)['foldedAt']);
        self::assertSame([[700, 1]], $this->batchesService->recordingRunEndNotificationsService->notifiedRunEnds);
        self::assertNull($this->batchesService->timeoutBatch(520), 'an ended run is not ended twice');
        self::assertFalse($this->batchesService->markSubAgentRunDeadlineEnded(520, new DateTime()));
        self::assertSame([[700, 1]], $this->batchesService->recordingRunEndNotificationsService->notifiedRunEnds);

        self::assertNull($this->batchesService->timeoutBatch(521));
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredConversation(102)['runState']);
        self::assertNull($this->findStoredBatch(521)['settledAt']);
        self::assertGreaterThan(time(), strtotime((string)$this->findStoredBatch(521)['deadlineAt']), 'a live child gets another window');

        self::assertNull($this->batchesService->timeoutBatch(522));
        self::assertSame(AIConversationRunState::QUEUED, $this->findStoredConversation(103)['runState']);
        self::assertGreaterThan(time(), strtotime((string)$this->findStoredBatch(522)['deadlineAt']), 'a child that waits for a slot gets another window');

        self::assertNull($this->batchesService->timeoutBatch(523));
        self::assertSame(AIConversationRunState::FAILED, $this->findStoredConversation(104)['runState']);
        $runWithoutASlot = $this->findStoredRunOfDelegateRow(703, 1);
        self::assertSame(DelegationSubAgentRun::STATUS_TIMED_OUT, $runWithoutASlot['status']);
        self::assertStringStartsWith('no free slot within', $runWithoutASlot['failureDetail']);
        self::assertSame([[700, 1], [703, 1]], $this->batchesService->recordingRunEndNotificationsService->notifiedRunEnds);
    }

    public function testALegacyBatchPastItsDeadlineFailsEveryChildThatHasNotEndedAndNamesTheParentToResume(): void
    {
        $this->insertParentConversation();
        $this->insertBatch(['id' => 530, 'expectedDelegationCount' => 5, 'deadlineAt' => $this->formatMomentSecondsAgo(60)]);
        $this->insertConversationOfThisEnvironment(['id' => 101, 'runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-101', 'runHeartbeatAt' => $this->formatMomentSecondsAgo(86400), 'delegationBatchId' => 530]);
        $this->insertConversationOfThisEnvironment(['id' => 102, 'runState' => AIConversationRunState::IDLE, 'delegationBatchId' => 530]);
        $this->insertConversationOfThisEnvironment(['id' => 103, 'runState' => AIConversationRunState::QUEUED, 'delegationBatchId' => 530]);
        $this->insertConversationOfThisEnvironment(['id' => 104, 'runState' => AIConversationRunState::COMPLETED, 'delegationBatchId' => 530]);

        self::assertSame(self::PARENT_CONVERSATION_ID, $this->batchesService->timeoutBatch(530), 'the deadline ends the batch whatever number of children was expected');
        foreach ([101, 102, 103] as $failedChildConversationId) {
            self::assertSame(AIConversationRunState::FAILED, $this->findStoredConversation($failedChildConversationId)['runState']);
            self::assertNull($this->findStoredConversation($failedChildConversationId)['runToken']);
        }
        self::assertSame(AIConversationRunState::COMPLETED, $this->findStoredConversation(104)['runState']);
        self::assertNotNull($this->findStoredBatch(530)['settledAt']);
        self::assertNull($this->findStoredBatch(530)['foldedAt'], 'the parent still has to read the results');
        self::assertNull($this->batchesService->timeoutBatch(530), 'a batch that has ended names no parent again');
        self::assertSame([], $this->batchesService->recordingRunEndNotificationsService->notifiedRunEnds);
    }

    public function testRunsThatHaveNotStartedAreCancelledWhenNewerWorkReplacesThem(): void
    {
        $this->insertParentConversation();
        $queuedAt = $this->formatMomentSecondsAgo(120);
        // 540: a sub-agent run whose child waits for a slot
        $this->insertDelegateRow(700, 0, AIConversationMessage::STATUS_RUNNING, $this->buildStoredSubAgentRecord(101, 1, [
            1 => ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_QUEUED, 'queuedAt' => $queuedAt],
        ]));
        $this->insertBatch(['id' => 540, 'spawnedByMessageId' => 700]);
        $this->insertConversationOfThisEnvironment(['id' => 101, 'runState' => AIConversationRunState::QUEUED, 'agentTurnOwedAt' => $queuedAt, 'delegationBatchId' => 540]);
        // 541: a sub-agent run whose child is at work
        $this->insertDelegateRow(701, 1, AIConversationMessage::STATUS_RUNNING, $this->buildStoredSubAgentRecord(102, 1, [
            1 => ['runNumber' => 1, 'status' => DelegationSubAgentRun::STATUS_RUNNING, 'queuedAt' => $queuedAt, 'startedAt' => $queuedAt],
        ]));
        $this->insertBatch(['id' => 541, 'spawnedByMessageId' => 701]);
        $this->insertConversationOfThisEnvironment(['id' => 102, 'runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-102', 'delegationBatchId' => 541]);
        // 542: a legacy batch of two children, neither started
        $this->insertBatch(['id' => 542, 'expectedDelegationCount' => 2]);
        $this->insertConversationOfThisEnvironment(['id' => 103, 'runState' => AIConversationRunState::IDLE, 'delegationBatchId' => 542]);
        $this->insertConversationOfThisEnvironment(['id' => 104, 'runState' => AIConversationRunState::QUEUED, 'delegationBatchId' => 542]);

        self::assertSame(3, $this->batchesService->cancelQueuedSubAgentRunsOfDeadlines([540, 541, 542, 540]));

        self::assertSame(AIConversationRunState::CANCELLED, $this->findStoredConversation(101)['runState']);
        self::assertNull($this->findStoredConversation(101)['agentTurnOwedAt']);
        $cancelledRun = $this->findStoredRunOfDelegateRow(700, 1);
        self::assertSame(DelegationSubAgentRun::STATUS_CANCELLED, $cancelledRun['status']);
        self::assertSame(DelegationSubAgentRun::FAILURE_REASON_SUPERSEDED, $cancelledRun['failureReason']);
        self::assertNotNull($this->findStoredBatch(540)['settledAt']);
        self::assertSame([[700, 1]], $this->batchesService->recordingRunEndNotificationsService->notifiedRunEnds);
        self::assertSame([self::PARENT_CONVERSATION_ID], $this->batchesService->recordingQueuePromotionSpawnService->rootConversationIdsOfferedFreedSlots);

        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredConversation(102)['runState'], 'a child at work finishes its turn');
        self::assertSame(DelegationSubAgentRun::STATUS_RUNNING, $this->findStoredRunOfDelegateRow(701, 1)['status']);
        self::assertNull($this->findStoredBatch(541)['settledAt']);

        self::assertSame(AIConversationRunState::CANCELLED, $this->findStoredConversation(103)['runState']);
        self::assertSame(AIConversationRunState::CANCELLED, $this->findStoredConversation(104)['runState']);
        self::assertNotNull($this->findStoredBatch(542)['settledAt']);
        self::assertNotNull($this->findStoredBatch(542)['foldedAt'], 'the parent is not asked to read the results of replaced work');
        self::assertFalse($this->batchesService->settleAndFoldAfterCancellation(542));
        self::assertFalse($this->batchesService->settleAndFoldAfterCancellation(541), 'the one expected child has not ended');
    }

    /** The child as the worker that ends it holds it: its id and the batch it belongs to. */
    protected function buildChild(int $childConversationId, int $batchId): AIConversation
    {
        $childConversation = $this->buildConversation($childConversationId);
        $childConversation->delegationBatchId = $batchId;
        return $childConversation;
    }

    /** Ends the running child `$childConversationId` with the token {@see insertRunningChild()} gave it. */
    protected function endChild(int $childConversationId, int $batchId, string $terminalRunState): AgentDelegationResumeClaim
    {
        return $this->batchesService->claimParentResumeOnChildTerminal($this->buildChild($childConversationId, $batchId), 'token-' . $childConversationId, $terminalRunState);
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    protected function getSortedIds(array $ids): array
    {
        sort($ids);
        return $ids;
    }
}
