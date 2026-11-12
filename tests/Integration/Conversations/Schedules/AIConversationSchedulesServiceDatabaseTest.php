<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Schedules;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleDueAgentSpace;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw statements of {@see \DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService} against the
 * database, on both engines: the badge count of unseen finished runs, the run costs behind the monthly estimate, the
 * due scan, the spaces with a run due, the fire claim (one winner, the same answer on both engines), the refund of a
 * claimed occurrence, the once-a-month budget notice read, and the state sweep with and without an application's
 * filter.
 */
class AIConversationSchedulesServiceDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = SchedulesServiceWithStateSweepFilterAndBudgetNotice::SWEPT_ACCOUNT_ID;

    protected const int OTHER_ACCOUNT_ID = 12;

    protected const int AGENT_SPACE_ID = 21;

    protected const int OTHER_AGENT_SPACE_ID = 22;

    protected SchedulesServiceWithStateSweepFilterAndBudgetNotice $schedulesService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->schedulesService = new SchedulesServiceWithStateSweepFilterAndBudgetNotice();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'first@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'second@example.test']);
        $this->insertAgentSpaceRow(['id' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
        $this->insertAgentSpaceRow(['id' => self::OTHER_AGENT_SPACE_ID, 'accountId' => self::OTHER_ACCOUNT_ID]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheBadgeCountsFinishedRunsCreatedAfterTheOwnerLastLooked(): void
    {
        $this->insertSchedule(31, ['agentSpaceId' => self::AGENT_SPACE_ID, 'created' => $this->formatMomentSecondsAgo(7200), 'ownerSawRunsUntilAt' => $this->formatMomentSecondsAgo(3600)]);
        $this->insertSchedule(32, ['agentSpaceId' => self::AGENT_SPACE_ID, 'created' => $this->formatMomentSecondsAgo(7200)]);
        $this->insertSchedule(33, ['agentSpaceId' => null, 'created' => $this->formatMomentSecondsAgo(7200)]);
        $this->insertSchedule(34, ['agentSpaceId' => null, 'accountId' => self::OTHER_ACCOUNT_ID, 'created' => $this->formatMomentSecondsAgo(7200)]);
        $this->insertRun(100, 31, AIConversationRunState::IDLE, ['created' => $this->formatMomentSecondsAgo(600)]);
        $this->insertRun(101, 31, AIConversationRunState::FAILED, ['created' => $this->formatMomentSecondsAgo(5400)]);
        $this->insertRun(102, 31, AIConversationRunState::RUNNING, ['created' => $this->formatMomentSecondsAgo(600)]);
        $this->insertRun(103, 32, AIConversationRunState::COMPLETED, ['created' => $this->formatMomentSecondsAgo(5400)]);
        $this->insertRun(104, 33, AIConversationRunState::IDLE, ['created' => $this->formatMomentSecondsAgo(600)]);
        $this->insertRun(105, 34, AIConversationRunState::IDLE, ['created' => $this->formatMomentSecondsAgo(600)]);

        self::assertSame(2, $this->schedulesService->countUnseenFinishedRunsOfAgentSpace($this->buildAgentSpace(self::AGENT_SPACE_ID)), 'a run after the last look, a run after the creation of a schedule never looked at');
        self::assertSame(1, $this->schedulesService->countUnseenFinishedRunsOfAccountWithoutAgentSpace($this->buildAccount(self::ACCOUNT_ID)));
        self::assertSame(0, $this->schedulesService->countUnseenFinishedRunsOfAgentSpace($this->buildAgentSpace(self::OTHER_AGENT_SPACE_ID)));
    }

    public function testTheMonthlyEstimateAveragesTheRunCostsOfEachScheduleAndFallsBackToTheSpaceAverage(): void
    {
        $this->insertSchedule(31, ['agentSpaceId' => self::AGENT_SPACE_ID, 'schedule' => '0 9 * * *']);
        $this->insertSchedule(32, ['agentSpaceId' => self::AGENT_SPACE_ID, 'schedule' => '0 9 * * *']);
        $this->insertSchedule(33, ['agentSpaceId' => self::AGENT_SPACE_ID, 'schedule' => '0 9 * * *', 'status' => AIConversationScheduleStatus::PAUSED]);
        $this->insertRun(100, 31, AIConversationRunState::IDLE, ['overheadCostsInUSD' => 0.5]);
        $this->insertCostRow(9001, 100, 1, 0.25, ['name' => 'readPage', 'status' => 'completed', 'executionCosts' => ['totalCostInUSD' => 0.25]]);
        $this->insertCostRow(9002, 100, 2, 0.5, null);
        $this->insertRun(101, 31, AIConversationRunState::IDLE, ['overheadCostsInUSD' => 0.5]);
        // a run of a schedule of another space is no part of the estimate
        $this->insertSchedule(34, ['agentSpaceId' => self::OTHER_AGENT_SPACE_ID, 'accountId' => self::OTHER_ACCOUNT_ID, 'schedule' => '0 9 * * *']);
        $this->insertRun(102, 34, AIConversationRunState::IDLE, ['overheadCostsInUSD' => 100.0]);

        $monthlyCostEstimate = $this->schedulesService->buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpace($this->buildAgentSpace(self::AGENT_SPACE_ID));

        self::assertSame(2, $monthlyCostEstimate->activeRecurringScheduleCount);
        self::assertFalse($monthlyCostEstimate->isCostAssumed);
        $costPerRunByScheduleId = [];
        foreach ($monthlyCostEstimate->perScheduleEstimates as $perScheduleEstimate) {
            $costPerRunByScheduleId[$perScheduleEstimate->scheduleId] = round($perScheduleEstimate->estimatedMonthlyCostUsd / $perScheduleEstimate->firingsPerMonth, 6);
        }
        ksort($costPerRunByScheduleId);
        // run 100: 0.25 + 0.25 + 0.5 + 0.5 overhead = 1.5; run 101: 0.5 overhead → schedule 31 averages 1.0, and so
        // does the space, which schedule 32 (no runs) takes
        self::assertSame([31 => 1.0, 32 => 1.0], $costPerRunByScheduleId);
    }

    public function testTheDueScanAndTheSpacesWithARunDueReadActiveSchedulesByTheirFireAnchor(): void
    {
        $this->insertSchedule(31, ['agentSpaceId' => self::AGENT_SPACE_ID, 'nextRunAt' => $this->formatMomentSecondsAgo(60)]);
        $this->insertSchedule(32, ['agentSpaceId' => self::AGENT_SPACE_ID, 'nextRunAt' => $this->formatMomentSecondsAgo(600)]);
        $this->insertSchedule(33, ['agentSpaceId' => self::AGENT_SPACE_ID, 'nextRunAt' => $this->formatMomentSecondsAgo(-600)]);
        $this->insertSchedule(34, ['agentSpaceId' => self::OTHER_AGENT_SPACE_ID, 'accountId' => self::OTHER_ACCOUNT_ID, 'nextRunAt' => $this->formatMomentSecondsAgo(120), 'status' => AIConversationScheduleStatus::PAUSED]);
        $this->insertSchedule(35, ['agentSpaceId' => null, 'nextRunAt' => $this->formatMomentSecondsAgo(30)]);
        $this->insertSchedule(36, ['agentSpaceId' => self::OTHER_AGENT_SPACE_ID, 'accountId' => self::OTHER_ACCOUNT_ID, 'nextRunAt' => $this->formatMomentSecondsAgo(-1200)]);

        self::assertSame([32, 31, 35], $this->schedulesService->findDueScheduleIds(), 'oldest anchor first');
        self::assertSame([32], $this->schedulesService->findDueScheduleIds(1));

        $agentSpacesWithRunDue = $this->schedulesService->findAgentSpacesWithRunDueBetween(new DateTime(), (new DateTime())->modify('+1 hour'));
        self::assertContainsOnlyInstancesOf(AIConversationScheduleDueAgentSpace::class, $agentSpacesWithRunDue);
        usort(
            $agentSpacesWithRunDue,
            static fn (AIConversationScheduleDueAgentSpace $first, AIConversationScheduleDueAgentSpace $second): int => $first->agentSpaceId <=> $second->agentSpaceId
        );
        self::assertSame(
            [[self::ACCOUNT_ID, self::AGENT_SPACE_ID], [self::OTHER_ACCOUNT_ID, self::OTHER_AGENT_SPACE_ID]],
            [
                [$agentSpacesWithRunDue[0]->accountId, $agentSpacesWithRunDue[0]->agentSpaceId],
                [$agentSpacesWithRunDue[1]->accountId, $agentSpacesWithRunDue[1]->agentSpaceId],
            ]
        );
        self::assertSame([], $this->schedulesService->findAgentSpacesWithRunDueBetween((new DateTime())->modify('+2 hours'), (new DateTime())->modify('+3 hours')));
    }

    public function testAnOccurrenceIsClaimedOnceAndARefundGivesTheIterationBack(): void
    {
        $this->insertSchedule(31, ['agentSpaceId' => self::AGENT_SPACE_ID, 'schedule' => '0 9 * * *', 'nextRunAt' => $this->formatMomentSecondsAgo(60), 'iterationsUsed' => 3]);

        $firstWorkerSchedule = $this->findSchedule(31);
        $secondWorkerSchedule = $this->findSchedule(31);
        self::assertTrue($this->schedulesService->claimFireOccurrence($firstWorkerSchedule));
        self::assertFalse($this->schedulesService->claimFireOccurrence($secondWorkerSchedule), 'the anchor the second worker loaded has moved');
        $storedSchedule = $this->findStoredRow(DBAIConversationScheduleModel::getTableName(), 31);
        self::assertSame(4, (int)$storedSchedule['iterationsUsed']);
        self::assertSame(4, $firstWorkerSchedule->iterationsUsed);
        self::assertGreaterThan(time(), strtotime((string)$storedSchedule['nextRunAt']), 'the anchor moved to the next occurrence');
        self::assertNotNull($storedSchedule['lastFiredAt']);

        $this->schedulesService->refundClaimedOccurrence($firstWorkerSchedule, reArmNow: false);
        self::assertSame(3, $firstWorkerSchedule->iterationsUsed);
        self::assertSame($storedSchedule['nextRunAt'], $this->findStoredRow(DBAIConversationScheduleModel::getTableName(), 31)['nextRunAt'], 'the next occurrence stands');

        $this->schedulesService->refundClaimedOccurrence($firstWorkerSchedule, reArmNow: true);
        $this->schedulesService->refundClaimedOccurrence($firstWorkerSchedule, reArmNow: true);
        $this->schedulesService->refundClaimedOccurrence($firstWorkerSchedule, reArmNow: true);
        $this->schedulesService->refundClaimedOccurrence($firstWorkerSchedule, reArmNow: true);
        self::assertSame(0, $firstWorkerSchedule->iterationsUsed, 'never below zero');
        self::assertLessThanOrEqual(time(), strtotime((string)$this->findStoredRow(DBAIConversationScheduleModel::getTableName(), 31)['nextRunAt']), 're-armed to now');

        // a paused schedule is never claimed
        $this->insertSchedule(32, ['agentSpaceId' => self::AGENT_SPACE_ID, 'schedule' => '0 9 * * *', 'nextRunAt' => $this->formatMomentSecondsAgo(60), 'status' => AIConversationScheduleStatus::PAUSED]);
        self::assertFalse($this->schedulesService->claimFireOccurrence($this->findSchedule(32)));
    }

    public function testTheBudgetNoticeIsStampedOncePerMonthAcrossTheSchedulesOfAnAccount(): void
    {
        $this->insertSchedule(31, ['agentSpaceId' => self::AGENT_SPACE_ID]);
        $this->insertSchedule(32, ['agentSpaceId' => self::AGENT_SPACE_ID, 'scheduleBudgetPotExhaustedNotifiedAt' => (new DateTime('first day of last month'))->format('Y-m-d 12:00:00')]);
        $this->insertSchedule(33, ['agentSpaceId' => self::OTHER_AGENT_SPACE_ID, 'accountId' => self::OTHER_ACCOUNT_ID, 'scheduleBudgetPotExhaustedNotifiedAt' => $this->formatMomentSecondsAgo(0)]);

        $this->schedulesService->notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhaustedNow($this->findSchedule(31));
        $firstStamp = $this->findStoredRow(DBAIConversationScheduleModel::getTableName(), 31)['scheduleBudgetPotExhaustedNotifiedAt'];
        self::assertNotNull($firstStamp, 'a stamp of last month and one of another account do not count');
        self::assertGreaterThanOrEqual(ScheduleBudgetPotsService::getCurrentCalendarMonthStartSqlDateTime(), (string)$firstStamp);

        $this->schedulesService->notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhaustedNow($this->findSchedule(32));
        self::assertSame(
            (new DateTime('first day of last month'))->format('Y-m-d 12:00:00'),
            (string)$this->findStoredRow(DBAIConversationScheduleModel::getTableName(), 32)['scheduleBudgetPotExhaustedNotifiedAt'],
            'the owner was told this month already'
        );
    }

    public function testTheStateSweepReadsPlatformPausedSchedulesAndTheSchedulesTheApplicationNames(): void
    {
        $this->insertSchedule(31, ['agentSpaceId' => self::AGENT_SPACE_ID]);
        $this->insertSchedule(32, ['agentSpaceId' => self::OTHER_AGENT_SPACE_ID, 'accountId' => self::OTHER_ACCOUNT_ID, 'status' => AIConversationScheduleStatus::PAUSED, 'stateReason' => 'AGENT_SPACE_SUSPENDED']);
        $this->insertSchedule(33, ['agentSpaceId' => self::AGENT_SPACE_ID, 'status' => AIConversationScheduleStatus::CANCELLED]);
        $this->insertSchedule(34, ['agentSpaceId' => null]);
        $this->insertSchedule(35, ['agentSpaceId' => self::AGENT_SPACE_ID, 'status' => AIConversationScheduleStatus::PAUSED]);

        self::assertSame([32], $this->schedulesService->findScheduleIdsForAutomationStateSweep(), 'without a filter: only what the platform paused');
        $this->schedulesService->usesStateSweepFilter = true;
        self::assertSame([31, 32, 35], $this->schedulesService->findScheduleIdsForAutomationStateSweep(), 'a schedule without a space finds no space row');
        self::assertSame([31], $this->schedulesService->findScheduleIdsForAutomationStateSweep(1));
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertSchedule(int $scheduleId, array $columnValues): void
    {
        $this->insertRow(DBAIConversationScheduleModel::getTableName(), $columnValues + [
            'id' => $scheduleId,
            'accountId' => self::ACCOUNT_ID,
            'task' => 'a task of schedule ' . $scheduleId,
            'status' => AIConversationScheduleStatus::ACTIVE,
            'iterationsUsed' => 0,
            'failureStreak' => 0,
            'created' => $this->formatMomentSecondsAgo(86400),
        ]);
    }

    /**
     * A run conversation of schedule `$scheduleId` in the partition of the schedule (its space and account).
     *
     * @param array<string, mixed> $columnValues
     */
    protected function insertRun(int $conversationId, int $scheduleId, string $runState, array $columnValues = []): void
    {
        $storedSchedule = $this->findStoredRow(DBAIConversationScheduleModel::getTableName(), $scheduleId);
        self::assertNotNull($storedSchedule);
        $this->insertRow(DBAIConversationModel::getTableName(), $columnValues + [
            'id' => $conversationId,
            'scheduleId' => $scheduleId,
            'accountId' => $storedSchedule['accountId'],
            'agentSpaceId' => $storedSchedule['agentSpaceId'],
            'runState' => $runState,
        ]);
    }

    /**
     * @param array<string, mixed>|null $toolCall
     */
    protected function insertCostRow(int $messageId, int $conversationId, int $indexInConversation, float $costInUSD, ?array $toolCall): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'messageType' => $toolCall === null ? AIConversationMessage::MESSAGE_TEXT : AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'costInUSD' => $costInUSD,
            'toolCall' => $toolCall === null ? null : json_encode($toolCall),
        ]);
    }

    protected function findSchedule(int $scheduleId): AIConversationSchedule
    {
        $schedule = $this->schedulesService->find($scheduleId, false);
        self::assertInstanceOf(AIConversationSchedule::class, $schedule);
        return $schedule;
    }

    protected function buildAgentSpace(int $agentSpaceId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $agentSpaceId;
        return $agentSpace;
    }

    protected function buildAccount(int $accountId): Account
    {
        $account = new Account();
        $account->id = $accountId;
        return $account;
    }

    /** The moment `$seconds` before now (after now for a negative number) as the database stores it. */
    protected function formatMomentSecondsAgo(int $seconds): string
    {
        return (new DateTime())->modify((-$seconds) . ' seconds')->format(DateTime::SIMPLE);
    }
}
