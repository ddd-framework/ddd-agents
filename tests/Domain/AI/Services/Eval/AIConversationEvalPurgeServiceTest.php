<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionThreadModel;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionApprovalRequestModel;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionActionModel;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTree;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversations;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTrees;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddingModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventModel;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Tests\Agents\Support\UnitTestCase;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;

/**
 * The account check, the chunk loop and the step order of the evaluation purge, without a database — through
 * {@see AIConversationEvalPurgeServiceRecordingDouble} and, for what an application adds, through
 * {@see AIConversationEvalPurgeServiceOfAnApplication}. The figures of the chunk loop (row limits, pauses, skips) are
 * the ones the purge has always produced.
 */
class AIConversationEvalPurgeServiceTest extends UnitTestCase
{
    protected static function buildApplySettings(): AIConversationEvalPurgeSettings
    {
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->dryRun = false;
        $settings->heavyRowsPerStatement = 50;
        $settings->lightRowsPerStatement = 500;
        $settings->idsPerStatement = 500;
        $settings->pauseMs = 100;
        $settings->slowWriteMs = 2000;
        $settings->maxSeconds = 300;
        return $settings;
    }

    protected static function buildPurgeKeys(): AIConversationEvalPurgeKeys
    {
        $purgeKeys = AIConversationEvalPurgeKeys::newInstance();
        $purgeKeys->setIdsOfKind(AIConversationEvalPurgeKeys::KIND_CONVERSATION, [10, 11]);
        $purgeKeys->setIdsOfKind(AIConversationEvalPurgeKeys::KIND_MESSAGE, [100, 101, 102]);
        return $purgeKeys;
    }

    public function testARunPurgesTheNamedEvalAccountsOrEveryConfiguredOneAndRefusesAnyOther(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $settings = AIConversationEvalPurgeSettings::newInstance();
        self::assertSame([7001, 7002], $service->resolveAccountIdsToPurge($settings));
        $settings->accountIds = [7002, 7002];
        self::assertSame([7002], $service->resolveAccountIdsToPurge($settings), 'the clean start of one account');
        $settings->accountIds = [7002, 9001];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a configured eval account: 9001 — its conversations are not purged.');
        $service->resolveAccountIdsToPurge($settings);
    }

    public function testTheModuleAllowsNoAccountForTheEvalOnlyCleanupByDefault(): void
    {
        $service = new AIConversationEvalPurgeService();
        self::assertSame([], $service->findAccountIdsAllowedForEvalOnlyPurge());
    }

    public function testTheEvalOnlyCleanupNamesTheAllowedAccountsAndSelectsOnlyMarkedRoots(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->accountIdsAllowedForEvalOnlyPurge = [8001, 8002];
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->accountIds = [8001, 7001];
        $settings->evalOnly = true;

        self::assertSame([8001, 7001], $service->resolveAccountIdsToPurge($settings), 'eval-only may name an allowed account beside the configured ones');
        $candidateSql = $service->buildCandidateRootConversationsSql($settings);
        self::assertStringContainsString("WHEN (IF(JSON_TYPE(JSON_EXTRACT(`c`.`runSettings`, '$.\"evalRun\"')) = 'NULL', NULL, JSON_UNQUOTE(JSON_EXTRACT(`c`.`runSettings`, '$.\"evalRun\"'))) = 'true') THEN 'evalRun'", $candidateSql);
        self::assertStringContainsString("THEN 'evalSeededHistory' ELSE NULL END) IS NOT NULL", $candidateSql, 'an unmarked root — the owner\'s own conversation — is never a candidate');

        $settings->evalOnly = false;
        self::assertStringNotContainsString('evalRun', $service->buildCandidateRootConversationsSql($settings), 'the configured evaluation accounts purge every tree');
    }

    public function testAPurgeOfEveryTreeRefusesAnAccountAllowedForTheEvalOnlyCleanupOnly(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->accountIdsAllowedForEvalOnlyPurge = [8001];
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->accountIds = [7001, 8001];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('8001');
        $service->resolveAccountIdsToPurge($settings);
    }

    public function testTheEvalOnlyCleanupAcceptsNoAccountBeyondTheAllowedAndTheConfiguredEvalAccounts(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->accountIdsAllowedForEvalOnlyPurge = [8001, 8002];
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->accountIds = [7001, 8001, 159];
        $settings->evalOnly = true;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a configured eval account nor one whose eval-marked trees may be purged (8001, 8002): 159 — its conversations are not purged.');
        $service->resolveAccountIdsToPurge($settings);
    }

    public function testConversationsOnKeptAgentSpacesAreNeverACandidate(): void
    {
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->keptAgentSpaceIds = [31, 0, 32, 31];
        $service = new AIConversationEvalPurgeServiceRecordingDouble();

        self::assertStringContainsString(
            ' AND (`c`.`agentSpaceId` IS NULL OR `c`.`agentSpaceId` NOT IN (:keptAgentSpaceIds))',
            $service->buildCandidateRootConversationsSql($settings),
            'a conversation at account level is on no space and stays a candidate'
        );
        $settings->keptAgentSpaceIds = [0];
        self::assertStringNotContainsString('keptAgentSpaceIds', $service->buildCandidateRootConversationsSql($settings), 'no positive id, no clause');
    }

    public function testStepRepeatsTheLimitedStatementUntilItWritesFewerRowsThanTheLimit(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->scriptedWrittenRows = [50, 50, 7];
        $report = AIConversationEvalPurgeReport::forRun(false);
        $messagesStep = AIConversationEvalPurgeStep::deleteRows('AIConversationMessages', 'id', AIConversationEvalPurgeKeys::KIND_MESSAGE, hasHeavyRows: true);

        $stepResult = $service->runStepInChunks($messagesStep, range(1, 107), self::buildApplySettings(), $report, 1000.0, 1000);

        self::assertSame(AIConversationEvalPurgeService::STEP_RESULT_DONE, $stepResult);
        self::assertCount(3, $service->executedChunks);
        self::assertSame(50, $service->executedChunks[0]['rowLimit']);
        self::assertSame(107, $report->rowsByStep['AIConversationMessages']);
        self::assertSame(107000, $report->estimatedBytesByStep['AIConversationMessages']);
        self::assertSame(3, $report->writes);
        self::assertSame([100, 100, 100], $service->pauses);
    }

    public function testLightTableUsesTheLightRowLimitAndIdListsAreChunked(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $report = AIConversationEvalPurgeReport::forRun(false);
        $turnEventsStep = AIConversationEvalPurgeStep::deleteRows('AIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);

        $service->runStepInChunks($turnEventsStep, range(1, 1200), self::buildApplySettings(), $report, 1000.0, 10);

        self::assertCount(3, $service->executedChunks);
        self::assertSame(500, $service->executedChunks[0]['rowLimit']);
        self::assertSame([500, 500, 200], array_map('count', array_column($service->executedChunks, 'ids')));
    }

    public function testLockWaitStopsTheStepAndCountsTheSkip(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->scriptedWrittenRows = [50, $this->createMock(LockWaitTimeoutException::class)];
        $report = AIConversationEvalPurgeReport::forRun(false);
        $messagesStep = AIConversationEvalPurgeStep::deleteRows('AIConversationMessages', 'id', AIConversationEvalPurgeKeys::KIND_MESSAGE, hasHeavyRows: true);

        $stepResult = $service->runStepInChunks($messagesStep, range(1, 200), self::buildApplySettings(), $report, 1000.0, 1);

        self::assertSame(AIConversationEvalPurgeService::STEP_RESULT_LOCK_SKIPPED, $stepResult);
        self::assertSame(1, $report->chunksSkippedOnLock);
        self::assertSame(50, $report->rowsByStep['AIConversationMessages']);
    }

    public function testUsedUpTimeBudgetWritesNothing(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $report = AIConversationEvalPurgeReport::forRun(false);
        $step = AIConversationEvalPurgeStep::deleteRows('AIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);

        $stepResult = $service->runStepInChunks($step, [1, 2], self::buildApplySettings(), $report, 1000.0 - 301, 1);

        self::assertSame(AIConversationEvalPurgeService::STEP_RESULT_TIME_BUDGET, $stepResult);
        self::assertSame([], $service->executedChunks);
    }

    public function testAnOversizedBudgetStillStopsTheWritesAtTheCap(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $report = AIConversationEvalPurgeReport::forRun(false);
        $step = AIConversationEvalPurgeStep::deleteRows('AIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $settings = self::buildApplySettings();
        $settings->maxSeconds = 1000;

        $stepResult = $service->runStepInChunks($step, [1, 2], $settings, $report, 1000.0 - (AIConversationEvalPurgeSettings::MAXIMUM_SECONDS + 1), 1);

        self::assertSame(AIConversationEvalPurgeService::STEP_RESULT_TIME_BUDGET, $stepResult);
        self::assertSame([], $service->executedChunks);
    }

    public function testSlowWritesDoubleThePauseAndFastWritesRelaxItAgain(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->scriptedWrittenRows = [50, 50, 50, 3];
        $service->secondsPerWrite = 3.0;
        $report = AIConversationEvalPurgeReport::forRun(false);
        $report->currentPauseMs = 100;
        $messagesStep = AIConversationEvalPurgeStep::deleteRows('AIConversationMessages', 'id', AIConversationEvalPurgeKeys::KIND_MESSAGE, hasHeavyRows: true);

        $service->runStepInChunks($messagesStep, range(1, 153), self::buildApplySettings(), $report, 1000.0, 1);

        self::assertSame([200, 400, 800, 1600], $service->pauses);
        self::assertSame(3000, $report->slowestWriteMs);
    }

    public function testBusyServerIsWaitedOutBeforeTheWrite(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->busyAnswersLeft = 2;
        $report = AIConversationEvalPurgeReport::forRun(false);
        $step = AIConversationEvalPurgeStep::deleteRows('AIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);

        $service->runStepInChunks($step, [1], self::buildApplySettings(), $report, 1000.0, 1);

        self::assertSame(2, $report->pausesForServerLoad);
        self::assertCount(1, $service->executedChunks);
    }

    public function testLockedStepLeavesTheMessagesAndConversationsUntouched(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        // the first step with ids (the turn events) hits the lock
        $service->scriptedWrittenRows = [$this->createMock(LockWaitTimeoutException::class)];
        $report = AIConversationEvalPurgeReport::forRun(false);

        $batchResult = $service->runPurgeSteps($service->buildPurgeSteps(), self::buildPurgeKeys(), self::buildApplySettings(), $report, 1000.0, []);

        self::assertSame(AIConversationEvalPurgeService::STEP_RESULT_LOCK_SKIPPED, $batchResult);
        self::assertCount(1, $service->executedChunks);
        self::assertSame(DBAIConversationTurnEventModel::getTableName(), $service->executedChunks[0]['label']);
        $executedLabels = array_column($service->executedChunks, 'label');
        self::assertNotContains(DBAIConversationMessageModel::getTableName(), $executedLabels);
        self::assertNotContains(DBAIConversationModel::getTableName(), $executedLabels);
    }

    public function testDryRunCountsEveryStepWithIdsAndWritesNothing(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $purgeKeys = self::buildPurgeKeys();
        $purgeKeys->setIdsOfKind(AIConversationEvalPurgeKeys::KIND_DELEGATION_BATCH, [5]);
        $settings = self::buildApplySettings();
        $settings->dryRun = true;
        $report = AIConversationEvalPurgeReport::forRun(true);
        $steps = $service->buildPurgeSteps();

        $batchResult = $service->runPurgeSteps($steps, $purgeKeys, $settings, $report, 1000.0, [DBAIConversationMessageModel::getTableName() => 1000]);

        self::assertSame(AIConversationEvalPurgeService::STEP_RESULT_DONE, $batchResult);
        self::assertSame([], $service->executedChunks);
        self::assertCount($steps->count(), $service->countedChunks, 'every module step matches conversations or messages');
        self::assertSame(3, $report->rowsByStep[DBAIConversationMessageModel::getTableName()]);
        self::assertSame(3000, $report->estimatedBytesByStep[DBAIConversationMessageModel::getTableName()]);
        self::assertSame(2, $report->rowsByStep[DBAIConversationModel::getTableName()]);
    }

    public function testTheModuleStepsDeleteDependentRowsBeforeTheirParentsAndTheConversationsLast(): void
    {
        $steps = (new AIConversationEvalPurgeServiceRecordingDouble())->buildPurgeSteps();
        $labels = $steps->getReportLabels();
        $positionOf = array_flip($labels);
        $messagesPosition = $positionOf[DBAIConversationMessageModel::getTableName()];

        self::assertCount(13, $steps->getElements());
        $lastStep = $steps->last();
        self::assertSame(DBAIConversationModel::getTableName(), $lastStep->tableName);
        self::assertTrue($lastStep->isDelete());
        self::assertLessThan($messagesPosition, $positionOf[DBAIConversationMessageEmbeddingModel::getTableName()]);
        foreach (array_values($steps->getElements()) as $stepPosition => $step) {
            if ($step->keyKind === AIConversationEvalPurgeKeys::KIND_MESSAGE && $step->tableName !== DBAIConversationMessageModel::getTableName()) {
                self::assertLessThan($messagesPosition, $stepPosition, $step->getReportLabel());
            }
        }
        self::assertCount(count($labels), array_unique($labels), 'every step label is unique');
        self::assertCount(count($labels), array_unique($steps->getStepKeys()), 'every step key is unique');
    }

    public function testTheAgentConnectionsTablesArePurgedOnlyWhileTheFeatureIsOn(): void
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $connectionColumns = [
            DBAgentConnectionActionModel::getTableName() . '.aiConversationId',
            DBAgentConnectionApprovalRequestModel::getTableName() . '.aiConversationId',
            DBAgentConnectionThreadModel::getTableName() . '.aiConversationId',
        ];
        $labelsWhileOff = $service->buildPurgeSteps()->getStepKeys();
        foreach ($connectionColumns as $connectionColumn) {
            self::assertNotContains('delete:' . $connectionColumn, $labelsWhileOff, 'no statement names a table that need not exist');
            self::assertArrayHasKey($connectionColumn, $service->getColumnsNotPurged());
        }

        $service->agentConnectionsEnabled = true;
        $labelsWhileOn = $service->buildPurgeSteps()->getStepKeys();
        self::assertCount(count($labelsWhileOff) + 3, $labelsWhileOn);
        foreach ($connectionColumns as $connectionColumn) {
            self::assertContains('delete:' . $connectionColumn, $labelsWhileOn);
            self::assertArrayNotHasKey($connectionColumn, $service->getColumnsNotPurged());
        }
    }

    public function testTheApplicationsStepsGoFirstByDefault(): void
    {
        $service = new AIConversationEvalPurgeServiceOfAnApplication();
        $stepKeys = $service->buildPurgeSteps()->getStepKeys();

        self::assertCount(16, $stepKeys);
        self::assertSame('delete:' . AIConversationEvalPurgeServiceOfAnApplication::RECORD_CHILD_TABLE_NAME . '.recordId', $stepKeys[0]);
        self::assertSame('delete:' . AIConversationEvalPurgeServiceOfAnApplication::RECORD_TABLE_NAME . '.aiConversationId', $stepKeys[1]);
        self::assertSame('nullify:' . AIConversationEvalPurgeServiceOfAnApplication::REPORT_TABLE_NAME . '.sourceMessageId', $stepKeys[2]);
        self::assertSame('delete:' . DBAIConversationTurnEventModel::getTableName() . '.aiConversationId', $stepKeys[3]);
        self::assertSame('delete:' . DBAIConversationModel::getTableName() . '.id', $stepKeys[15]);
    }

    public function testTheApplicationOrdersTheWholeListByItsStepKeys(): void
    {
        $service = new AIConversationEvalPurgeServiceOfAnApplication();
        $service->interleavesItsSteps = true;
        $stepKeys = $service->buildPurgeSteps()->getStepKeys();

        self::assertCount(16, $stepKeys, 'no step is lost or doubled by the order');
        self::assertSame('delete:' . DBAIConversationTurnEventModel::getTableName() . '.aiConversationId', $stepKeys[0]);
        self::assertSame('delete:' . AIConversationEvalPurgeServiceOfAnApplication::RECORD_CHILD_TABLE_NAME . '.recordId', $stepKeys[1]);
        self::assertSame('nullify:' . AIConversationEvalPurgeServiceOfAnApplication::REPORT_TABLE_NAME . '.sourceMessageId', $stepKeys[3]);
        self::assertSame('delete:' . DBAIConversationModel::getTableName() . '.id', $stepKeys[15], 'the steps the list does not name keep their order after it');
    }

    public function testTheKeysOfABatchHoldTheTreesConversationsTheirRowsAndTheApplicationsKinds(): void
    {
        $service = new AIConversationEvalPurgeServiceOfAnApplication();
        $service->scriptedIdsByTableColumn = [
            DBAIConversationMessageModel::getTableName() . '.aiConversationId' => [100, 101],
            DBAIConversationDelegationBatchModel::getTableName() . '.parentConversationId' => [5],
            AIConversationEvalPurgeServiceOfAnApplication::RECORD_TABLE_NAME . '.aiConversationId' => [70],
        ];
        $trees = AIConversationEvalTrees::newInstance();
        foreach ([10, 12] as $rootConversationId) {
            $tree = AIConversationEvalTree::fromConversations($rootConversationId, AIConversationEvalTreeConversations::newInstance(), null);
            $trees->add($tree);
        }

        $purgeKeys = $service->buildPurgeKeys($trees, self::buildApplySettings());

        self::assertSame([10, 12], $purgeKeys->getConversationIds());
        self::assertSame([100, 101], $purgeKeys->getMessageIds());
        self::assertSame([5], $purgeKeys->getDelegationBatchIds());
        self::assertSame([70], $purgeKeys->getIdsOfKind(AIConversationEvalPurgeServiceOfAnApplication::KIND_RECORD));
    }

    public function testTheApplicationsMarkerIsCheckedAfterTheModulesAndCannotReplaceOne(): void
    {
        $service = new AIConversationEvalPurgeServiceOfAnApplication();
        $markerConditionsByMarker = $service->buildEvalMarkerConditionsByMarker('c');

        self::assertSame(
            [AIConversationEvalPurgeService::EVAL_MARKER_EVAL_RUN, AIConversationEvalPurgeService::EVAL_MARKER_SEEDED_HISTORY, AIConversationEvalPurgeServiceOfAnApplication::EVAL_MARKER_EVAL_WORKSPACE],
            array_keys($markerConditionsByMarker)
        );
        self::assertStringNotContainsString('1 = 0', $service->buildEvalMarkerSql('c'));
        self::assertStringEndsWith("THEN 'evalWorkspace' ELSE NULL END)", $service->buildEvalMarkerSql('c'));
    }

    public function testTheApplicationAddsItsColumnsToTheColumnsNotPurged(): void
    {
        $columnsNotPurged = (new AIConversationEvalPurgeServiceOfAnApplication())->getColumnsNotPurged();
        self::assertArrayHasKey(DBAIConversationModel::getTableName() . '.rootConversationId', $columnsNotPurged);
        self::assertArrayHasKey(AIConversationEvalPurgeServiceOfAnApplication::RECORD_CHILD_TABLE_NAME . '.recordId', $columnsNotPurged);
    }
}
