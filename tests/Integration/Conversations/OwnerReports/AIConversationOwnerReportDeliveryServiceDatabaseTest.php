<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\OwnerReports;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw statements of {@see AIConversationOwnerReportDeliveryService} against the database, on both engines: the
 * delivery claim won exactly once (decided by the affected-row count, which the WHERE makes the same on both
 * engines), its revert, the take-back of a stale claim only, and the close of a schedule's runs and open report
 * obligations.
 */
class AIConversationOwnerReportDeliveryServiceDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 11;

    protected const int SCHEDULE_ID = 31;

    protected const int OTHER_SCHEDULE_ID = 32;

    protected const int CONVERSATION_ID = 100;

    protected AIConversationOwnerReportDeliveryService $ownerReportDeliveryService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->ownerReportDeliveryService = new AIConversationOwnerReportDeliveryService();
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheDeliveryClaimIsWonOnceAndARevertMakesItClaimableAgain(): void
    {
        $this->insertConversation(['id' => self::CONVERSATION_ID, 'runState' => AIConversationRunState::COMPLETED, 'reportOwedAt' => $this->formatMomentSecondsAgo(60)]);
        $firstDeliverer = $this->buildConversation(self::CONVERSATION_ID);
        $secondDeliverer = $this->buildConversation(self::CONVERSATION_ID);

        self::assertTrue($this->ownerReportDeliveryService->claimReportDelivery($firstDeliverer));
        self::assertInstanceOf(DateTime::class, $firstDeliverer->reportDeliveredAt);
        self::assertNotNull($this->findStoredConversation(self::CONVERSATION_ID)['reportDeliveredAt']);
        self::assertFalse($this->ownerReportDeliveryService->claimReportDelivery($secondDeliverer), 'a claim already taken counts 0 rows on both engines');
        self::assertFalse(isset($secondDeliverer->reportDeliveredAt));
        self::assertFalse($this->ownerReportDeliveryService->claimReportDelivery(new AIConversation()));

        $this->ownerReportDeliveryService->revertReportDeliveryClaim($firstDeliverer);
        self::assertNull($firstDeliverer->reportDeliveredAt);
        self::assertNull($this->findStoredConversation(self::CONVERSATION_ID)['reportDeliveredAt']);
        self::assertTrue($this->ownerReportDeliveryService->claimReportDelivery($secondDeliverer));
    }

    public function testOnlyAStaleClaimIsTakenBackAndOnlyOnce(): void
    {
        $this->insertConversation(['id' => self::CONVERSATION_ID, 'runState' => AIConversationRunState::RUNNING, 'reportDeliveredAt' => $this->formatMomentSecondsAgo(2)]);
        $this->insertConversation(['id' => self::CONVERSATION_ID + 1, 'runState' => AIConversationRunState::RUNNING]);

        self::assertFalse($this->ownerReportDeliveryService->revertStaleReportDeliveryClaim($this->buildConversation(self::CONVERSATION_ID), 10), 'a fresh claim belongs to a deliverer at work');
        self::assertNotNull($this->findStoredConversation(self::CONVERSATION_ID)['reportDeliveredAt']);
        self::assertFalse($this->ownerReportDeliveryService->revertStaleReportDeliveryClaim($this->buildConversation(self::CONVERSATION_ID + 1), 10), 'no claim to take back');

        $this->getDatabaseConnection()->executeStatement(
            'UPDATE ' . $this->getSqlDialect()->quote(DBAIConversationModel::getTableName()) . ' SET ' . $this->getSqlDialect()->quote('reportDeliveredAt') . ' = ?',
            [$this->formatMomentSecondsAgo(60)]
        );
        $staleClaimHolder = $this->buildConversation(self::CONVERSATION_ID);
        $staleClaimHolder->reportDeliveredAt = new DateTime();
        self::assertTrue($this->ownerReportDeliveryService->revertStaleReportDeliveryClaim($staleClaimHolder, 10));
        self::assertNull($this->findStoredConversation(self::CONVERSATION_ID)['reportDeliveredAt']);
        self::assertNotNull($staleClaimHolder->reportDeliveredAt, 'the stamp in memory stays until the caller claims again');
        self::assertFalse($this->ownerReportDeliveryService->revertStaleReportDeliveryClaim($this->buildConversation(self::CONVERSATION_ID), 10), 'taken back once');
        self::assertFalse($this->ownerReportDeliveryService->revertStaleReportDeliveryClaim(new AIConversation(), 10));
    }

    public function testClosingAScheduleCancelsItsUnfinishedRunsAndClosesItsOpenReportObligations(): void
    {
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'owner@example.test']);
        $this->insertRow(DBAIConversationScheduleModel::getTableName(), ['id' => self::SCHEDULE_ID, 'accountId' => self::ACCOUNT_ID, 'task' => 'a weekly task']);
        $this->insertRow(DBAIConversationScheduleModel::getTableName(), ['id' => self::OTHER_SCHEDULE_ID, 'accountId' => self::ACCOUNT_ID, 'task' => 'another task']);
        $deliveredAnHourAgo = $this->formatMomentSecondsAgo(3600);
        $owedAnHourAgo = ['reportOwedAt' => $deliveredAnHourAgo];
        $this->insertConversation(['id' => 101, 'runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-101', 'scheduleId' => self::SCHEDULE_ID] + $owedAnHourAgo);
        $this->insertConversation(['id' => 102, 'runState' => AIConversationRunState::QUEUED, 'scheduleId' => self::SCHEDULE_ID, 'suppressionReason' => 'an earlier reason', 'suppressedAt' => $deliveredAnHourAgo]);
        $this->insertConversation(['id' => 103, 'runState' => AIConversationRunState::COMPLETED, 'scheduleId' => self::SCHEDULE_ID] + $owedAnHourAgo);
        $this->insertConversation(['id' => 104, 'runState' => AIConversationRunState::COMPLETED, 'scheduleId' => self::SCHEDULE_ID, 'reportDeliveredAt' => $deliveredAnHourAgo] + $owedAnHourAgo);
        $this->insertConversation(['id' => 105, 'runState' => AIConversationRunState::IDLE, 'scheduleId' => self::SCHEDULE_ID]);
        $this->insertConversation(['id' => 106, 'runState' => AIConversationRunState::RUNNING, 'scheduleId' => self::OTHER_SCHEDULE_ID] + $owedAnHourAgo);

        self::assertSame(4, $this->ownerReportDeliveryService->cancelRunsAndCloseReportObligationsOfSchedule(self::SCHEDULE_ID, 'projectDeleted'), 'two runs cancelled, two obligations closed');

        $cancelledRun = $this->findStoredConversation(101);
        self::assertSame(AIConversationRunState::CANCELLED, $cancelledRun['runState']);
        self::assertNull($cancelledRun['runToken']);
        self::assertSame('projectDeleted', $cancelledRun['suppressionReason']);
        self::assertNotNull($cancelledRun['reportDeliveredAt'], 'its open obligation is closed too');
        self::assertSame(AIConversationRunState::CANCELLED, $this->findStoredConversation(102)['runState']);
        self::assertSame('an earlier reason', $this->findStoredConversation(102)['suppressionReason'], 'an existing reason is kept');
        self::assertNull($this->findStoredConversation(102)['reportDeliveredAt'], 'no obligation was owed');
        $closedObligation = $this->findStoredConversation(103);
        self::assertSame(AIConversationRunState::COMPLETED, $closedObligation['runState']);
        self::assertNotNull($closedObligation['reportDeliveredAt']);
        self::assertSame('projectDeleted', $closedObligation['suppressionReason']);
        self::assertNotNull($closedObligation['suppressedAt']);
        self::assertSame($deliveredAnHourAgo, $this->formatStoredMoment($this->findStoredConversation(104)['reportDeliveredAt']), 'a delivered report is not touched');
        self::assertNull($this->findStoredConversation(104)['suppressionReason']);
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredConversation(105)['runState']);
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredConversation(106)['runState'], 'another schedule');
        self::assertNull($this->findStoredConversation(106)['reportDeliveredAt']);

        self::assertSame(0, $this->ownerReportDeliveryService->cancelRunsAndCloseReportObligationsOfSchedule(self::SCHEDULE_ID, 'projectDeleted'), 'nothing left to close');
    }

    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        return $conversation;
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertConversation(array $columnValues): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), $columnValues);
    }

    /** @return array<string, mixed> */
    protected function findStoredConversation(int $conversationId): array
    {
        $storedConversation = $this->findStoredRow(DBAIConversationModel::getTableName(), $conversationId);
        self::assertNotNull($storedConversation, 'conversation ' . $conversationId . ' is stored');
        return $storedConversation;
    }

    protected function formatMomentSecondsAgo(int $seconds): string
    {
        return (new DateTime())->modify('-' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }

    protected function formatStoredMoment(mixed $storedMoment): string
    {
        return (new DateTime((string)$storedMoment))->format('Y-m-d H:i:s');
    }
}
