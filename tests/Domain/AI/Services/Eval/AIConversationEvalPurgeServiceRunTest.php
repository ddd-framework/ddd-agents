<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversation;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversations;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Tests\Agents\Support\UnitTestCase;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;

/**
 * One whole run of the evaluation purge over scripted reads ({@see AIConversationEvalPurgeServiceRecordingDouble}):
 * the cutoffs come from the wall clock in UTC, the batches resume after the last checked root, every verdict is
 * counted, the progress line of a batch is the one the purge has always printed, and the run stops for the reasons
 * it has always stopped for.
 */
class AIConversationEvalPurgeServiceRunTest extends UnitTestCase
{
    /** @var int An account that is no configured evaluation account */
    protected const int FOREIGN_ACCOUNT_ID = 9999;

    protected static function buildTreeConversations(int $rootConversationId, int $accountId, string $created, ?string $runState = AIConversationRunState::COMPLETED): AIConversationEvalTreeConversations
    {
        $treeConversations = AIConversationEvalTreeConversations::newInstance();
        $rootConversation = AIConversationEvalTreeConversation::forConversation($rootConversationId, $accountId, $runState, false);
        $rootConversation->setActivityMoment('created', $created);
        $treeConversations->add($rootConversation);
        $childConversation = AIConversationEvalTreeConversation::forConversation($rootConversationId + 1, $accountId, AIConversationRunState::COMPLETED, false);
        $childConversation->setActivityMoment('created', $created);
        $treeConversations->add($childConversation);
        return $treeConversations;
    }

    protected static function buildServiceWithThreeRoots(): AIConversationEvalPurgeServiceRecordingDouble
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->scriptedCandidateBatches = [[10, 20, 30]];
        $service->treeConversationsByRootConversationId = [
            10 => self::buildTreeConversations(10, 7001, '2026-03-01 08:00:00'),
            20 => self::buildTreeConversations(20, 7002, '2026-03-11 08:00:00'),
            30 => self::buildTreeConversations(30, self::FOREIGN_ACCOUNT_ID, '2026-03-01 08:00:00'),
        ];
        $service->scriptedIdsByTableColumn = [DBAIConversationMessageModel::getTableName() . '.aiConversationId' => [100, 101, 102]];
        $service->scriptedAverageRowBytesByTableName = [DBAIConversationMessageModel::getTableName() => AIConversationEvalPurgeReport::BYTES_PER_MEGABYTE];
        return $service;
    }

    public function testADryRunCountsTheOldTreesOfTheEvalAccountsAndStopsWhenNoTreeIsLeft(): void
    {
        $service = self::buildServiceWithThreeRoots();
        $progressLinesRecorder = new AgentEvalProgressLinesRecorder();

        $report = $service->purgeEvalConversations(AIConversationEvalPurgeSettings::newInstance(), $progressLinesRecorder);

        self::assertSame('2026-03-10 12:00:00', $report->activityCutoff, '48 hours before the wall clock, in UTC');
        self::assertSame(
            [
                ['accountIds' => [7001, 7002], 'activityCutoff' => '2026-03-10 12:00:00', 'afterRootConversationId' => 0],
                ['accountIds' => [7001, 7002], 'activityCutoff' => '2026-03-10 12:00:00', 'afterRootConversationId' => 30],
            ],
            $service->candidateReads,
            'the next batch starts after the last checked root'
        );
        self::assertSame(3, $report->treesChecked);
        self::assertSame(1, $report->treesPurged);
        self::assertSame(1, $report->treesSkippedRecentActivity);
        self::assertSame(1, $report->treesSkippedNotEvalAccount);
        self::assertSame(2, $report->conversationsInPurgedTrees);
        self::assertSame([7001 => 1], $report->treesPurgedByAccountId);
        self::assertSame([], $service->executedChunks, 'a dry run writes nothing');
        // 12 module steps match the 2 conversations, 4 match the 3 messages
        self::assertSame(30, $report->getRowsTotal());
        self::assertSame(2, $report->rowsByStep[DBAIConversationModel::getTableName()]);
        self::assertSame(AIConversationEvalPurgeReport::STOPPED_NO_TREE_LEFT, $report->stoppedBecause);
        self::assertSame(30, $report->lastRootConversationId);
        self::assertSame(['[dry run] roots up to 30: 3 trees checked, 1 purged (2 conversations), 30 rows, 3 MB, average write - ms'], $progressLinesRecorder->progressLines);
    }

    public function testTheFreshHeartbeatCutoffIsComputedFromTheSameClock(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->scriptedCandidateBatches = [[10]];
        $treeConversations = self::buildTreeConversations(10, 7001, '2026-03-01 08:00:00');
        $treeConversations->first()->setActivityMoment('runHeartbeatAt', '2026-03-12 11:45:00');
        $service->treeConversationsByRootConversationId = [10 => $treeConversations];

        $report = $service->purgeEvalConversations(AIConversationEvalPurgeSettings::newInstance());

        self::assertSame(1, $report->treesSkippedFreshHeartbeat, 'a heartbeat after 11:30 UTC (30 minutes before the clock) keeps the tree');
        self::assertSame(0, $report->treesPurged);
    }

    public function testAnEvalOnlyRunOfOneRootRecordsTheMarkerAndStopsAfterItsBatch(): void
    {
        $service = self::buildServiceWithThreeRoots();
        $service->scriptedCandidateBatches = [[10], [40]];
        $service->scriptedEvalMarkersByRootConversationId = [10 => AIConversationEvalPurgeService::EVAL_MARKER_EVAL_RUN];
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->evalOnly = true;
        $settings->onlyRootConversationId = 10;
        $settings->dryRun = false;
        $settings->pauseMs = 0;

        $report = $service->purgeEvalConversations($settings);

        self::assertCount(1, $service->candidateReads, 'a run of one root reads one batch');
        self::assertSame(1, $report->treesPurged);
        self::assertSame(AIConversationEvalPurgeService::EVAL_MARKER_EVAL_RUN, $report->sampleRootConversations->first()->marker);
        self::assertCount(13, $service->executedChunks, 'one write per module step with ids');
        self::assertSame(AIConversationEvalPurgeReport::STOPPED_NO_TREE_LEFT, $report->stoppedBecause);
    }

    public function testALockedBatchIsLeftIncompleteAndTheRunGoesOnWithTheNextBatch(): void
    {
        $service = self::buildServiceWithThreeRoots();
        $service->scriptedCandidateBatches = [[10], [50]];
        $service->treeConversationsByRootConversationId[50] = self::buildTreeConversations(50, 7002, '2026-03-01 08:00:00');
        $service->scriptedWrittenRows = [$this->createMock(LockWaitTimeoutException::class)];
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->dryRun = false;
        $settings->pauseMs = 0;

        $report = $service->purgeEvalConversations($settings);

        self::assertSame(1, $report->batchesLeftIncomplete);
        self::assertSame(1, $report->chunksSkippedOnLock);
        self::assertSame(1, $report->treesPurged, 'the second batch went through');
        self::assertSame([7002 => 1], $report->treesPurgedByAccountId);
    }

    public function testATimeBudgetUsedUpWhileCheckingResumesAtTheFirstUncheckedRoot(): void
    {
        $service = self::buildServiceWithThreeRoots();
        $service->secondsPerTreeCheck = 6.0;
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->maxSeconds = 10;
        $progressLinesRecorder = new AgentEvalProgressLinesRecorder();

        $report = $service->purgeEvalConversations($settings, $progressLinesRecorder);

        self::assertSame(2, $report->treesChecked);
        self::assertSame(29, $report->lastRootConversationId, 'the next run starts again at root 30');
        self::assertSame(1, $report->treesPurged, 'the trees checked so far are the batch');
        self::assertSame(AIConversationEvalPurgeReport::STOPPED_TIME_BUDGET, $report->stoppedBecause);
        self::assertCount(1, $progressLinesRecorder->progressLines);
        self::assertSame(12, $report->runSeconds);
    }

    public function testARunWithoutEvalAccountsReadsNothing(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->configuredEvalAccountIds = [];

        $report = $service->purgeEvalConversations(AIConversationEvalPurgeSettings::newInstance());

        self::assertSame(AIConversationEvalPurgeReport::STOPPED_NO_EVAL_ACCOUNTS, $report->stoppedBecause);
        self::assertSame([], $service->candidateReads);
    }

    public function testABusyServerPausesAWritingRunBeforeItsNextBatch(): void
    {
        $service = self::buildServiceWithThreeRoots();
        $service->scriptedCandidateBatches = [];
        $service->busyAnswersLeft = 1;
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->dryRun = false;

        $report = $service->purgeEvalConversations($settings);

        self::assertSame(1, $report->pausesForServerLoad);
        self::assertSame([5000], $service->pauses);
    }
}
