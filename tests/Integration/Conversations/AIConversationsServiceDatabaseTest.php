<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleNewestRun;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleRunSummary;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Repo\DB\Dialect\Locks\PostgresLockService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * The raw statements of {@see AIConversationsService} against the database, on both engines: the selectors of the
 * recovery passes (owed reports, owed turns, an inbound left without a run, stuck runs and their expiry), the newest
 * open chat of a space, the cost reads of a schedule's runs and of a conversation tree, the booking of overhead
 * costs into the stored document, the finalize marks, the slices written over the stored working state, the
 * environment backfill and the start lock of an entry point.
 */
class AIConversationsServiceDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 11;

    protected const int AGENT_SPACE_ID = 21;

    protected const int SCHEDULE_ID = 31;

    protected const int ROOT_CONVERSATION_ID = 100;

    protected const int CHILD_CONVERSATION_ID = 101;

    protected const string OTHER_ENVIRONMENT = 'another-workspace';

    protected ?Connection $connectionOfAnotherWorker = null;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
    }

    protected function onTearDown(): void
    {
        AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        $this->connectionOfAnotherWorker?->close();
        $this->connectionOfAnotherWorker = null;
    }

    public function testOwedReportsAreSelectedForEndedRunsOfThisEnvironmentOutsideEvalRuns(): void
    {
        $owedAt = $this->formatMomentSecondsAgo(600);
        $this->insertConversationOfThisEnvironment(['id' => 100, 'runState' => AIConversationRunState::IDLE, 'reportOwedAt' => $owedAt]);
        $this->insertConversationOfThisEnvironment(['id' => 101, 'runState' => AIConversationRunState::RUNNING, 'reportOwedAt' => $owedAt]);
        $this->insertConversationOfThisEnvironment(['id' => 102, 'runState' => AIConversationRunState::IDLE, 'reportOwedAt' => $owedAt, 'reportDeliveredAt' => $owedAt]);
        $this->insertConversation([
            'id' => 103,
            'runState' => AIConversationRunState::IDLE,
            'reportOwedAt' => $owedAt,
            'agentWorkingState' => json_encode(['environment' => self::OTHER_ENVIRONMENT]),
        ]);
        $this->insertConversationOfThisEnvironment(['id' => 104, 'runState' => AIConversationRunState::IDLE, 'reportOwedAt' => $owedAt, 'runSettings' => '{"evalRun":true}']);
        $this->insertConversationOfThisEnvironment(['id' => 105, 'runState' => AIConversationRunState::IDLE, 'reportOwedAt' => $owedAt, 'rootConversationId' => 104]);
        $this->insertConversationOfThisEnvironment([
            'id' => 106,
            'runState' => AIConversationRunState::IDLE,
            'reportOwedAt' => $this->formatMomentSecondsAgo(900),
            'runSettings' => '{"evalRun":false}',
        ]);

        self::assertSame([106, 100], $this->getConversationsService()->findConversationIdsOwingReports(), 'oldest obligation first');
        self::assertSame([106], $this->getConversationsService()->findConversationIdsOwingReports(1));
        self::assertSame(
            [100, 101, 102, 104, 105, 106],
            $this->getSortedIds($this->getConversationsService()->keepConversationIdsOfCurrentEnvironment([100, 101, 102, 103, 104, 105, 106, 999]))
        );
    }

    public function testOwedTurnsAndInboundsLeftWithoutARunAreSelectedOncePastTheQuietWindow(): void
    {
        // the handover of a turn that no run took over
        $this->insertConversationOfThisEnvironment(['id' => 100, 'runState' => AIConversationRunState::IDLE, 'agentTurnOwedAt' => $this->formatMomentSecondsAgo(300)]);
        $this->insertConversationOfThisEnvironment(['id' => 101, 'runState' => AIConversationRunState::IDLE, 'agentTurnOwedAt' => $this->formatMomentSecondsAgo(1)]);
        $this->insertConversationOfThisEnvironment([
            'id' => 102,
            'runState' => AIConversationRunState::IDLE,
            'agentTurnOwedAt' => $this->formatMomentSecondsAgo(300),
            'runSettings' => '{"evalRun":true}',
        ]);
        // an inbound newer than the last heartbeat, no handover marker
        $this->insertConversationOfThisEnvironment([
            'id' => 110,
            'runState' => AIConversationRunState::IDLE,
            'lastInboundMessageAt' => $this->formatMomentSecondsAgo(200),
            'runHeartbeatAt' => $this->formatMomentSecondsAgo(400),
        ]);
        // a failed attempt after the inbound: selected while the newest text is not the agent's
        $this->insertConversationOfThisEnvironment([
            'id' => 111,
            'runState' => AIConversationRunState::IDLE,
            'lastInboundMessageAt' => $this->formatMomentSecondsAgo(500),
            'runHeartbeatAt' => $this->formatMomentSecondsAgo(300),
            'resumeAttempts' => 1,
        ]);
        $this->insertTextRow(9111, 111, 1, AIConversationMessage::ROLE_USER);
        $this->insertConversationOfThisEnvironment([
            'id' => 112,
            'runState' => AIConversationRunState::IDLE,
            'lastInboundMessageAt' => $this->formatMomentSecondsAgo(500),
            'runHeartbeatAt' => $this->formatMomentSecondsAgo(300),
            'resumeAttempts' => 1,
        ]);
        $this->insertTextRow(9121, 112, 1, AIConversationMessage::ROLE_USER);
        $this->insertTextRow(9122, 112, 2, AIConversationMessage::ROLE_ASSISTANT);
        // answered and quiet: the heartbeat is newer than the inbound and no attempt failed
        $this->insertConversationOfThisEnvironment([
            'id' => 113,
            'runState' => AIConversationRunState::IDLE,
            'lastInboundMessageAt' => $this->formatMomentSecondsAgo(500),
            'runHeartbeatAt' => $this->formatMomentSecondsAgo(300),
        ]);

        self::assertSame([100], $this->getConversationsService()->findConversationIdsOwingContinuations(60));
        self::assertSame([110, 111], $this->getConversationsService()->findConversationIdsWithInboundLeftIdle(60, 50, 3600), 'newest inbound first');
        self::assertSame([], $this->getConversationsService()->findConversationIdsWithInboundLeftIdle(60, 50, 100), 'nothing older than the lookback');
    }

    public function testStuckRunsAreRevivedInsideTheGraceWindowAndExpiredPastIt(): void
    {
        $this->insertConversationOfThisEnvironment(['id' => 100, 'runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-100', 'runHeartbeatAt' => $this->formatMomentSecondsAgo(400)]);
        $this->insertConversationOfThisEnvironment(['id' => 101, 'runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-101', 'runHeartbeatAt' => $this->formatMomentSecondsAgo(20)]);
        $this->insertConversationOfThisEnvironment([
            'id' => 102,
            'runState' => AIConversationRunState::AWAITING_DELEGATES,
            'runHeartbeatAt' => $this->formatMomentSecondsAgo(AIConversationsService::FOLD_OWED_STALE_AFTER_SECONDS + 30),
        ]);
        $this->insertConversationOfThisEnvironment(['id' => 103, 'runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-103', 'runHeartbeatAt' => $this->formatMomentSecondsAgo(7200)]);
        $this->insertConversation([
            'id' => 104,
            'runState' => AIConversationRunState::RUNNING,
            'runHeartbeatAt' => $this->formatMomentSecondsAgo(7200),
            'agentWorkingState' => json_encode(['environment' => self::OTHER_ENVIRONMENT]),
        ]);
        $this->insertConversationOfThisEnvironment([
            'id' => 105,
            'runState' => AIConversationRunState::IDLE,
            'purposeName' => 'Some.Purpose',
            'runHeartbeatAt' => $this->formatMomentSecondsAgo(900),
        ]);

        self::assertSame([100, 102], $this->getSortedIds($this->getConversationsService()->findStuckConversationIds(300, 3600)));
        self::assertSame([105], $this->getConversationsService()->findQuietIdlePurposedConversationIds('Some.Purpose', 600));
        self::assertSame([], $this->getConversationsService()->findQuietIdlePurposedConversationIds('Some.Purpose', 1200));

        self::assertSame([103], $this->getConversationsService()->expireStuckConversationsPastMaxAge(3600));

        $expiredConversation = $this->findStoredConversation(103);
        self::assertSame(AIConversationRunState::FAILED, $expiredConversation['runState']);
        self::assertNull($expiredConversation['runToken']);
        self::assertNotNull($expiredConversation['reportOwedAt'], 'the late report pass delivers for the expired run');
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredConversation(104)['runState'], 'a run of another environment stays');
        self::assertSame([], $this->getConversationsService()->expireStuckConversationsPastMaxAge(3600), 'an expired run is not expired twice');
    }

    public function testTheNewestOpenChatOfASpaceIsACustomerDialogWithARecentInbound(): void
    {
        $this->insertAccountAndAgentSpace();
        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;
        $recentInbound = $this->formatMomentSecondsAgo(60);
        $plainChat = ['agentSpaceId' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID, 'runState' => AIConversationRunState::IDLE];
        $this->insertConversationOfThisEnvironment(['id' => 100, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(120)] + $plainChat);
        $this->insertConversationOfThisEnvironment(['id' => 101, 'lastInboundMessageAt' => $recentInbound] + $plainChat);
        $this->insertConversationOfThisEnvironment(['id' => 102, 'lastInboundMessageAt' => $recentInbound, 'purposeName' => 'Some.Purpose'] + $plainChat);
        $this->insertConversationOfThisEnvironment(['id' => 103, 'lastInboundMessageAt' => $recentInbound, 'runSettings' => '{"evalRun":true}'] + $plainChat);
        $this->insertConversationOfThisEnvironment(['id' => 104, 'lastInboundMessageAt' => $recentInbound, 'accountId' => self::ACCOUNT_ID, 'runState' => AIConversationRunState::IDLE]);
        $inboundSince = (new DateTime())->modify('-600 seconds');

        self::assertSame(101, $this->getConversationsService()->findNewestOpenCustomerChatIdOfAgentSpace($agentSpace, $inboundSince));
        self::assertSame(100, $this->getConversationsService()->findNewestOpenCustomerChatIdOfAgentSpace($agentSpace, $inboundSince, 100), 'the chat the run was requested from wins');
        self::assertSame(100, $this->getConversationsService()->findNewestOpenCustomerChatIdOfAgentSpace($agentSpace, $inboundSince, null, 101), 'the run\'s own conversation never qualifies');
        self::assertSame(101, $this->getConversationsService()->findNewestOpenCustomerChatIdOfAgentSpace($agentSpace, $inboundSince, 102), 'a preferred chat that does not qualify is not returned');
        self::assertNull($this->getConversationsService()->findNewestOpenCustomerChatIdOfAgentSpace($agentSpace, (new DateTime())->modify('-10 seconds')));
    }

    public function testRunSummariesOfAScheduleAndTreeCostsAddMessageToolExecutionAndOverheadCosts(): void
    {
        $this->insertAccountAndAgentSpace();
        $this->insertRow(DBAIConversationScheduleModel::getTableName(), ['id' => self::SCHEDULE_ID, 'accountId' => self::ACCOUNT_ID, 'task' => 'a weekly task']);
        $this->insertConversation(['id' => 99, 'runState' => AIConversationRunState::FAILED, 'scheduleId' => self::SCHEDULE_ID]);
        $this->insertConversation([
            'id' => self::ROOT_CONVERSATION_ID,
            'runState' => AIConversationRunState::IDLE,
            'scheduleId' => self::SCHEDULE_ID,
            'summary' => '{"title":"Weekly check","outcome":"All fine"}',
            'overheadCostsInUSD' => 0.5,
        ]);
        $this->insertCostRow(9001, self::ROOT_CONVERSATION_ID, 1, 0.10, 'model-b', ['name' => 'delegate', 'status' => 'completed', 'executionCosts' => ['totalCostInUSD' => 0.05]], ['inputTokens' => 100, 'outputTokens' => 10]);
        $this->insertCostRow(9002, self::ROOT_CONVERSATION_ID, 2, 0.20, 'model-a', null, ['inputTokens' => 200, 'cachedInputTokens' => 50, 'outputTokens' => 20]);
        $this->insertConversation([
            'id' => self::CHILD_CONVERSATION_ID,
            'runState' => AIConversationRunState::IDLE,
            'scheduleId' => self::SCHEDULE_ID,
            'rootConversationId' => self::ROOT_CONVERSATION_ID,
            'delegatedFromMessageId' => 9001,
            'overheadCostsInUSD' => 0.25,
        ]);
        $this->insertCostRow(9003, self::CHILD_CONVERSATION_ID, 1, 0.01, 'model-a', ['name' => 'readPage', 'status' => 'completed'], []);

        $runSummaries = $this->getConversationsService()->findScheduleRunSummaries(self::SCHEDULE_ID);
        self::assertSame([self::ROOT_CONVERSATION_ID, 99], array_column($runSummaries, 'conversationId'), 'root runs only, newest first');
        self::assertContainsOnlyInstancesOf(AIConversationScheduleRunSummary::class, $runSummaries);
        self::assertSame('Weekly check', $runSummaries[0]->title);
        self::assertSame('All fine', $runSummaries[0]->outcome);
        self::assertSame(AIConversationRunState::IDLE, $runSummaries[0]->runState);
        self::assertEqualsWithDelta(0.85, $runSummaries[0]->costInUSD, 0.00001, 'messages 0.30, tool execution 0.05, overhead 0.50');
        self::assertNull($runSummaries[1]->title);
        self::assertEqualsWithDelta(0.0, $runSummaries[1]->costInUSD, 0.00001);
        self::assertSame([99], array_column($this->getConversationsService()->findScheduleRunSummaries(self::SCHEDULE_ID, 20, self::ROOT_CONVERSATION_ID), 'conversationId'));
        $newestRunByScheduleId = $this->getConversationsService()->findNewestScheduleRunSummaryPerSchedule([self::SCHEDULE_ID, 999]);
        self::assertSame([self::SCHEDULE_ID], array_keys($newestRunByScheduleId), 'a schedule without a run has no element');
        $newestRun = $newestRunByScheduleId[self::SCHEDULE_ID];
        self::assertInstanceOf(AIConversationScheduleNewestRun::class, $newestRun);
        self::assertSame(
            [self::SCHEDULE_ID, self::ROOT_CONVERSATION_ID, AIConversationRunState::IDLE, 'All fine'],
            [$newestRun->scheduleId, $newestRun->conversationId, $newestRun->runState, $newestRun->outcomeExcerpt]
        );

        $treeCostSummary = $this->getConversationsService()->sumConversationTreeCostsByRootConversationId([self::ROOT_CONVERSATION_ID])->getForRootConversationId(self::ROOT_CONVERSATION_ID);
        self::assertNotNull($treeCostSummary);
        self::assertEqualsWithDelta(1.11, $treeCostSummary->costInUSD, 0.00001, 'messages and tool executions 0.36, overhead 0.75');
        self::assertEqualsWithDelta(0.75, $treeCostSummary->overheadCostsInUSD, 0.00001);
        self::assertSame(300, $treeCostSummary->inputTokens);
        self::assertSame(30, $treeCostSummary->outputTokens);
        self::assertSame(3, $treeCostSummary->messageCount);
        self::assertSame(2, $treeCostSummary->conversationCount);

        $conversationCostRows = $this->getConversationsService()->findConversationTreeCostRows(self::ROOT_CONVERSATION_ID)->getElements();
        self::assertCount(2, $conversationCostRows);
        [$rootCostRow, $childCostRow] = array_values($conversationCostRows);
        self::assertSame(self::ROOT_CONVERSATION_ID, $rootCostRow->conversationId);
        self::assertFalse($rootCostRow->isDelegationChild);
        self::assertSame('Weekly check', $rootCostRow->title);
        self::assertEqualsWithDelta(0.85, $rootCostRow->costInUSD, 0.00001);
        self::assertEqualsWithDelta(0.5, $rootCostRow->overheadCostsInUSD, 0.00001);
        self::assertSame(300, $rootCostRow->inputTokens);
        self::assertSame(50, $rootCostRow->cachedInputTokens);
        self::assertSame(2, $rootCostRow->messageCount);
        self::assertSame('model-a, model-b', $rootCostRow->modelNames);
        self::assertSame(self::CHILD_CONVERSATION_ID, $childCostRow->conversationId);
        self::assertTrue($childCostRow->isDelegationChild);
        self::assertNull($childCostRow->title);
        self::assertEqualsWithDelta(0.26, $childCostRow->costInUSD, 0.00001);
        self::assertSame('model-a', $childCostRow->modelNames);

        $toolCallTraceEntries = array_values($this->getConversationsService()->findToolCallTraceForConversationTree(self::ROOT_CONVERSATION_ID)->getElements());
        self::assertCount(2, $toolCallTraceEntries);
        self::assertSame([self::ROOT_CONVERSATION_ID, self::CHILD_CONVERSATION_ID], [$toolCallTraceEntries[0]->conversationId, $toolCallTraceEntries[1]->conversationId]);
        self::assertSame(['delegate', 'readPage'], [$toolCallTraceEntries[0]->toolName, $toolCallTraceEntries[1]->toolName]);
        self::assertEqualsWithDelta(0.15, $toolCallTraceEntries[0]->costInUSD, 0.00001);

        $treeRows = $this->getConversationsService()->findTreeRowsForRecordedRunComparison($this->buildConversation(self::ROOT_CONVERSATION_ID));
        self::assertSame([self::ROOT_CONVERSATION_ID, self::CHILD_CONVERSATION_ID], array_map('intval', array_column($treeRows['conversations'], 'id')));
        self::assertSame([9001, 9002, 9003], array_map('intval', array_column($treeRows['messages'], 'id')), 'the root first, each conversation by index');
        self::assertSame([], $treeRows['delegationBatches']);
        self::assertSame([], $treeRows['compactionNodes']);

        $delegationAnchor = $this->getConversationsService()->findDelegationAnchorById(self::CHILD_CONVERSATION_ID);
        self::assertNotNull($delegationAnchor);
        self::assertSame(9001, $delegationAnchor->delegatedFromMessageId);
        self::assertSame(self::ROOT_CONVERSATION_ID, $delegationAnchor->rootConversationId);
        self::assertNull($this->getConversationsService()->findDelegationAnchorById(999));
        self::assertTrue($this->getConversationsService()->conversationHasModelCall($this->buildConversation(self::ROOT_CONVERSATION_ID)));
        self::assertFalse($this->getConversationsService()->conversationHasModelCall($this->buildConversation(self::CHILD_CONVERSATION_ID)));
    }

    public function testOverheadCostsAddUpInTheColumnAndInTheStoredDocumentPerUsageScope(): void
    {
        $this->insertConversation(['id' => 100, 'runState' => AIConversationRunState::IDLE]);
        $this->insertConversation([
            'id' => 101,
            'runState' => AIConversationRunState::IDLE,
            'overheadCostsInUSD' => 1.5,
            'overheadBookedCosts' => '{"totalCostInUSD":1.5,"bookingCount":2,"costInUSDByUsageScope":[]}',
        ]);
        $firstBookedCosts = new AIUsageBookedCosts();
        $firstBookedCosts->addBookedCost('AGENT.TURN.DECISION', 0.001);
        $firstBookedCosts->addBookedCost('AGENT.HISTORY.SUMMARY', 0.002);
        $secondBookedCosts = new AIUsageBookedCosts();
        $secondBookedCosts->addBookedCost('AGENT.TURN.DECISION', 0.004);

        $this->getConversationsService()->addOverheadCostsToConversation(100, $firstBookedCosts);
        $this->getConversationsService()->addOverheadCostsToConversation(100, $secondBookedCosts);
        $this->getConversationsService()->addOverheadCostsToConversation(100, new AIUsageBookedCosts());
        $this->getConversationsService()->addOverheadCostsToConversation(101, $secondBookedCosts);

        $storedConversation = $this->findStoredConversation(100);
        self::assertEqualsWithDelta(0.007, (float)$storedConversation['overheadCostsInUSD'], 0.0000001);
        $storedBookedCosts = json_decode((string)$storedConversation['overheadBookedCosts'], true);
        self::assertEqualsWithDelta(0.007, $storedBookedCosts['totalCostInUSD'], 0.0000001);
        self::assertEquals(3, $storedBookedCosts['bookingCount']);
        self::assertSame(['AGENT.HISTORY.SUMMARY', 'AGENT.TURN.DECISION'], $this->getSortedKeys($storedBookedCosts['costInUSDByUsageScope']));
        self::assertEqualsWithDelta(0.005, $storedBookedCosts['costInUSDByUsageScope']['AGENT.TURN.DECISION'], 0.0000001);
        self::assertEqualsWithDelta(0.002, $storedBookedCosts['costInUSDByUsageScope']['AGENT.HISTORY.SUMMARY'], 0.0000001);

        $storedConversationWithEarlierBookings = $this->findStoredConversation(101);
        self::assertEqualsWithDelta(1.504, (float)$storedConversationWithEarlierBookings['overheadCostsInUSD'], 0.0000001);
        $storedEarlierBookedCosts = json_decode((string)$storedConversationWithEarlierBookings['overheadBookedCosts'], true);
        self::assertEqualsWithDelta(1.504, $storedEarlierBookedCosts['totalCostInUSD'], 0.0000001);
        self::assertEquals(3, $storedEarlierBookedCosts['bookingCount']);
        self::assertSame(['AGENT.TURN.DECISION'], array_keys($storedEarlierBookedCosts['costInUSDByUsageScope']), 'an empty list became the object the scopes are written into');
        self::assertEqualsWithDelta(0.004, $storedEarlierBookedCosts['costInUSDByUsageScope']['AGENT.TURN.DECISION'], 0.0000001);
    }

    public function testIdleConversationsAreMarkedFinalizedOnceAndOweACompletionCompactionUntilItIsStamped(): void
    {
        $this->insertConversation(['id' => 100, 'runState' => AIConversationRunState::IDLE, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(7200)]);
        $this->insertConversation(['id' => 101, 'runState' => AIConversationRunState::RUNNING, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(7200)]);
        $this->insertConversation(['id' => 102, 'runState' => AIConversationRunState::IDLE, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(60)]);
        $this->insertConversation(['id' => 103, 'runState' => AIConversationRunState::IDLE]);
        $momentBeforeTheMark = $this->formatMomentSecondsAgo(2);

        self::assertSame(1, $this->getConversationsService()->countIdleConversationsToFinalize(60));
        self::assertSame(1, $this->getConversationsService()->markIdleConversationsFinalized(60));
        self::assertSame(0, $this->getConversationsService()->markIdleConversationsFinalized(60), 'a finalized conversation is not marked twice');

        $finalizedAt = (string)$this->findStoredConversation(100)['finalizedAt'];
        self::assertGreaterThanOrEqual($momentBeforeTheMark, substr($finalizedAt, 0, 19), 'the stamp is the PHP clock');
        self::assertLessThanOrEqual($this->formatMomentSecondsAgo(-2), substr($finalizedAt, 0, 19));
        self::assertNull($this->findStoredConversation(101)['finalizedAt']);
        self::assertSame([100], $this->getConversationsService()->findFinalizedConversationIdsOwingCompletionCompaction(10));

        $this->writeConversationColumns(100, ['finalizeCompactedAt' => $this->formatMomentSecondsAgo(-5)]);

        self::assertSame([], $this->getConversationsService()->findFinalizedConversationIdsOwingCompletionCompaction(10));
    }

    public function testSlicesAreWrittenOverTheStoredWorkingStateAndASnapshotIsWrittenOnce(): void
    {
        $this->insertConversation([
            'id' => 100,
            'runState' => AIConversationRunState::RUNNING,
            'runToken' => 'token-of-the-run',
            'agentWorkingState' => '{"environment":"some-environment","dayContextText":null,"taskPlan":{"open":true}}',
            'taskPlanOpenAt' => $this->formatMomentSecondsAgo(60),
        ]);
        $conversation = $this->buildConversation(100);

        $this->getConversationsService()->persistDayContextTextSlice($conversation, ' Monday, market day ');
        $this->getConversationsService()->persistDayContextTextSlice($conversation, 'another day');
        $this->getConversationsService()->persistLocalSituationTextSlice($conversation, 'Rain until noon');
        $this->getConversationsService()->persistLocalSituationTextSlice($conversation, 'Sun all day');

        $storedWorkingState = $this->findStoredWorkingState(100);
        self::assertSame('Monday, market day', $storedWorkingState['dayContextText'], 'a key stored as JSON null is written, a written key is kept');
        self::assertSame('Rain until noon', $storedWorkingState['localSituationText'], 'an absent key is written, a written key is kept');
        self::assertSame('some-environment', $storedWorkingState['environment']);
        self::assertSame(['open' => true], $storedWorkingState['taskPlan']);
        self::assertSame('Monday, market day', $conversation->getAgentWorkingState()?->dayContextText);
        self::assertSame('Rain until noon', $conversation->getAgentWorkingState()?->localSituationText);

        $this->getConversationsService()->persistTaskPlanSlice($conversation, null, 'token-of-the-run');

        $storedWorkingState = $this->findStoredWorkingState(100);
        self::assertArrayNotHasKey('taskPlan', $storedWorkingState, 'an explicit null deletes the key');
        self::assertSame('Monday, market day', $storedWorkingState['dayContextText']);
        self::assertNull($this->findStoredConversation(100)['taskPlanOpenAt']);

        // the same write again changes nothing: the held claim tells it apart from a lost one
        $this->getConversationsService()->persistTaskPlanSlice($conversation, null, 'token-of-the-run');

        self::assertFalse($this->getConversationsService()->persistTaskPlanSliceWhileIdle($conversation, new TaskPlan()), 'a run owns the conversation');
        $this->writeConversationColumns(100, ['runState' => AIConversationRunState::IDLE, 'runToken' => null]);
        self::assertTrue($this->getConversationsService()->persistTaskPlanSliceWhileIdle($conversation, new TaskPlan()));
        self::assertTrue($this->getConversationsService()->persistTaskPlanSliceWhileIdle($conversation, new TaskPlan()), 'an identical plan on an idle conversation is stored state');
        self::assertArrayHasKey('taskPlan', $this->findStoredWorkingState(100));

        $this->expectException(AgentRunClaimLostException::class);
        $this->getConversationsService()->persistTaskPlanSlice($conversation, null, 'token-of-the-run');
    }

    public function testTheEnvironmentBackfillStampsEveryUnstampedConversationInBatches(): void
    {
        $this->insertConversation(['id' => 100, 'runState' => AIConversationRunState::IDLE]);
        $this->insertConversation(['id' => 101, 'runState' => AIConversationRunState::IDLE, 'agentWorkingState' => '{}']);
        $this->insertConversation(['id' => 102, 'runState' => AIConversationRunState::IDLE, 'agentWorkingState' => '{"taskPlan":{"open":true}}']);
        $this->insertConversation(['id' => 103, 'runState' => AIConversationRunState::IDLE, 'agentWorkingState' => json_encode(['environment' => self::OTHER_ENVIRONMENT])]);

        self::assertSame(3, $this->getConversationsService()->countConversationsWithoutEnvironment());
        self::assertSame(3, $this->getConversationsService()->backfillEnvironmentOnUnstampedConversations('the-first-environment', 2));
        self::assertSame(0, $this->getConversationsService()->countConversationsWithoutEnvironment());
        self::assertSame(0, $this->getConversationsService()->backfillEnvironmentOnUnstampedConversations('a-later-environment', 2));

        self::assertSame(['environment' => 'the-first-environment'], $this->findStoredWorkingState(100));
        self::assertSame('the-first-environment', $this->findStoredWorkingState(101)['environment']);
        self::assertSame(['open' => true], $this->findStoredWorkingState(102)['taskPlan'], 'the other keys of the working state stay');
        self::assertSame('the-first-environment', $this->findStoredWorkingState(102)['environment']);
        self::assertSame(self::OTHER_ENVIRONMENT, $this->findStoredWorkingState(103)['environment'], 'a stamp is never replaced');
    }

    public function testTheStartLockOfAnEntryPointIsHeldUntilItIsGivenBack(): void
    {
        $account = new Account();
        $account->id = self::ACCOUNT_ID;

        $startLock = $this->getConversationsService()->lockEntryPointStart($account, null, 'some-entry-point');

        self::assertTrue($startLock->isReleaseOwed());
        self::assertFalse($this->canAnotherWorkerTakeTheNamedLock($startLock->lockName));

        $this->getConversationsService()->unlockEntryPointStart($startLock);
        $this->getConversationsService()->unlockEntryPointStart($startLock);

        self::assertFalse($startLock->isReleaseOwed());
        self::assertTrue($this->canAnotherWorkerTakeTheNamedLock($startLock->lockName));
    }

    public function testTheRecentSessionsOfASpaceOfTheAccountLevelAndOfTheWholeAccountAreThreeReads(): void
    {
        $this->insertAccountAndAgentSpace();
        $this->insertAgentSpaceRow(['id' => 22, 'accountId' => self::ACCOUNT_ID]);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => 12, 'email' => 'second@example.test']);
        $this->insertAgentSpaceRow(['id' => 23, 'accountId' => 12]);
        $sessionWithSummary = ['runState' => AIConversationRunState::IDLE, 'summary' => '{"title":"A session","outcome":"Done"}'];
        $this->insertConversation(['id' => 100, 'accountId' => self::ACCOUNT_ID, 'agentSpaceId' => self::AGENT_SPACE_ID, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(400)] + $sessionWithSummary);
        $this->insertConversation(['id' => 101, 'accountId' => self::ACCOUNT_ID, 'agentSpaceId' => 22, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(300)] + $sessionWithSummary);
        $this->insertConversation(['id' => 102, 'accountId' => self::ACCOUNT_ID, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(200)] + $sessionWithSummary);
        $this->insertConversation(['id' => 103, 'accountId' => self::ACCOUNT_ID, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(100)] + $sessionWithSummary);
        $this->insertConversation(['id' => 104, 'accountId' => self::ACCOUNT_ID, 'agentSpaceId' => self::AGENT_SPACE_ID, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(90), 'runState' => AIConversationRunState::IDLE]);
        $this->insertConversation(['id' => 105, 'accountId' => self::ACCOUNT_ID, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(80), 'purposeName' => AIConversationPurpose::getCustomerHiddenPurposeNames()[0]] + $sessionWithSummary);
        $this->insertConversation(['id' => 110, 'accountId' => 12, 'agentSpaceId' => 23, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(70)] + $sessionWithSummary);
        $this->insertConversation(['id' => 111, 'accountId' => 12, 'lastInboundMessageAt' => $this->formatMomentSecondsAgo(60)] + $sessionWithSummary);
        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;

        self::assertSame([100], $this->getIdsInOrder($this->getConversationsService()->findRecentConversationsWithSummariesOfAgentSpace(self::ACCOUNT_ID, $agentSpace)));
        self::assertSame(
            [103, 102],
            $this->getIdsInOrder($this->getConversationsService()->findRecentConversationsWithSummariesOfAgentSpace(self::ACCOUNT_ID, null)),
            'without a space: the account-level sessions of this account, never those of its spaces or of another account'
        );
        self::assertSame(
            [103, 102, 101, 100],
            $this->getIdsInOrder($this->getConversationsService()->findRecentConversationsWithSummariesOfAccountIdAcrossAgentSpaces(self::ACCOUNT_ID)),
            'across the spaces: every space and the account level of this account, newest activity first'
        );
        self::assertSame(
            [102, 101],
            $this->getIdsInOrder($this->getConversationsService()->findRecentConversationsWithSummariesOfAccountIdAcrossAgentSpaces(self::ACCOUNT_ID, 103, 2)),
            'the conversation that asks is left out and the limit holds'
        );
        self::assertSame([111, 110], $this->getIdsInOrder($this->getConversationsService()->findRecentConversationsWithSummariesOfAccountIdAcrossAgentSpaces(12)));
    }

    public function testHiddenConversationsAreNamedWithTheirSubAgentsAndASuppressionKeepsItsFirstReason(): void
    {
        $this->insertConversation(['id' => 100, 'runState' => AIConversationRunState::IDLE, 'purposeName' => AIConversationPurpose::getCustomerHiddenPurposeNames()[0]]);
        $this->insertConversation(['id' => 101, 'runState' => AIConversationRunState::IDLE, 'rootConversationId' => 100]);
        $this->insertConversation(['id' => 102, 'runState' => AIConversationRunState::IDLE, 'purposeName' => 'Some.Purpose']);

        self::assertSame([100, 101], $this->getSortedIds($this->getConversationsService()->findCustomerHiddenConversationIds([100, 101, 102, 999])));

        $this->getConversationsService()->recordSuppressionOfConversation($this->buildConversation(102), 'the first reason');
        $firstSuppressedAt = $this->findStoredConversation(102)['suppressedAt'];
        $this->getConversationsService()->recordSuppressionOfConversation($this->buildConversation(102), 'a later reason');

        self::assertSame('the first reason', $this->findStoredConversation(102)['suppressionReason']);
        self::assertNotNull($firstSuppressedAt);
        self::assertSame($firstSuppressedAt, $this->findStoredConversation(102)['suppressedAt']);
    }

    protected function getConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    /**
     * @param AIConversations $conversations
     * @return int[] The ids of the conversations in the order the read returned them
     */
    protected function getIdsInOrder(AIConversations $conversations): array
    {
        $idsInOrder = [];
        foreach ($conversations->getElements() as $conversation) {
            $idsInOrder[] = (int)$conversation->id;
        }
        return $idsInOrder;
    }

    /** A conversation object that names the stored row `$conversationId` and nothing else. */
    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        return $conversation;
    }

    /** The moment `$seconds` before now (a negative number: after now) as the database stores it. */
    protected function formatMomentSecondsAgo(int $seconds): string
    {
        return (new DateTime())->modify(($seconds >= 0 ? '-' : '+') . abs($seconds) . ' seconds')->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertConversation(array $columnValues): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), $columnValues);
    }

    /**
     * A conversation stamped with the environment this process runs in.
     *
     * @param array<string, mixed> $columnValues
     */
    protected function insertConversationOfThisEnvironment(array $columnValues): void
    {
        $this->insertConversation($columnValues + ['agentWorkingState' => json_encode(['environment' => AIConversationsService::currentEnvironment()])]);
    }

    protected function insertAccountAndAgentSpace(): void
    {
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'first@example.test']);
        $this->insertAgentSpaceRow(['id' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
    }

    protected function insertTextRow(int $messageId, int $conversationId, int $indexInConversation, string $role): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'role' => $role,
            'message' => 'text of row ' . $messageId,
        ]);
    }

    /**
     * A row with a model cost; a tool call row when a tool call document is given.
     *
     * @param array<string, mixed>|null $toolCall
     * @param array<string, int> $tokenCounts
     */
    protected function insertCostRow(int $messageId, int $conversationId, int $indexInConversation, float $costInUSD, string $aiModelName, ?array $toolCall, array $tokenCounts): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), $tokenCounts + [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'messageType' => $toolCall === null ? AIConversationMessage::MESSAGE_TEXT : AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'toolCall' => $toolCall === null ? null : json_encode($toolCall),
            'costInUSD' => $costInUSD,
            'aiModelName' => $aiModelName,
        ]);
    }

    /** @return array<string, mixed> */
    protected function findStoredConversation(int $conversationId): array
    {
        $storedConversation = $this->findStoredRow(DBAIConversationModel::getTableName(), $conversationId);
        self::assertNotNull($storedConversation, 'conversation ' . $conversationId . ' is stored');
        return $storedConversation;
    }

    /** @return array<string, mixed> */
    protected function findStoredWorkingState(int $conversationId): array
    {
        return json_decode((string)$this->findStoredConversation($conversationId)['agentWorkingState'], true);
    }

    /**
     * Writes columns of a stored conversation the way another process would have.
     *
     * @param array<string, mixed> $columnValues
     */
    protected function writeConversationColumns(int $conversationId, array $columnValues): void
    {
        $assignments = [];
        foreach (array_keys($columnValues) as $columnName) {
            $assignments[] = '`' . $columnName . '` = ?';
        }
        $this->getDatabaseConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET ' . implode(', ', $assignments) . ' WHERE `id` = ?',
            [...array_values($columnValues), $conversationId]
        );
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

    /**
     * @param array<string, mixed> $document
     * @return string[]
     */
    protected function getSortedKeys(array $document): array
    {
        $keys = array_keys($document);
        sort($keys);
        return $keys;
    }

    /** Whether a second database session gets the named lock at once; it gives the lock back when it got it. */
    protected function canAnotherWorkerTakeTheNamedLock(string $lockName): bool
    {
        if ($this->connectionOfAnotherWorker === null) {
            $connection = $this->getDatabaseConnection();
            $this->connectionOfAnotherWorker = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration());
        }
        // PostgreSQL takes named locks on a connection of the lock service's own; MariaDB on the connection given
        $lockServiceOfAnotherWorker = $this->isPostgres()
            ? new PostgresLockService('DEFAULT', $this->getSqlDialect())
            : $this->getSqlDialect()->locks($this->connectionOfAnotherWorker);
        $lockTaken = $lockServiceOfAnotherWorker->acquire($lockName, 0);
        if ($lockTaken) {
            $lockServiceOfAnotherWorker->release($lockName);
        }
        return $lockTaken;
    }
}
