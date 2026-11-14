<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * An application that redeclares the skip words of the budget pot and of a run still in progress gets its own words
 * from the occurrence spawn, and the service's questions recognise them: producer and question read the same binding.
 */
class AIConversationSchedulesServiceRedeclaredSkipWordsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceService(AgentAutomationEligibilityService::class, new AutomationEligibilityServiceWithEverythingEligibleDouble());
        $this->replaceService(ScheduleBudgetPotsService::class, new ScheduleBudgetPotsServiceWithExhaustedPotDouble());
        StaticRegistry::$entityServices = [];
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$entityServices = [];
    }

    public function testAnExhaustedPotIsSkippedWithTheApplicationsWord(): void
    {
        $this->replaceService(AIConversationsService::class, new ConversationsServiceWithScheduleRunDouble(null));
        $schedulesService = new SchedulesServiceWithOwnPotAndRunWordsDouble();

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule($this->buildRecurringScheduleOfSpace());

        self::assertNull($occurrenceSpawn->runConversation);
        self::assertSame('SHOP_BUDGET_PAUSED', $occurrenceSpawn->skipReason);
        self::assertTrue($schedulesService->isSkipReasonOfExhaustedBudgetPot((string)$occurrenceSpawn->skipReason));
        self::assertFalse($schedulesService->isSkipReasonOfExhaustedBudgetPot(AIConversationSchedulesService::OCCURRENCE_SKIP_REASON_BUDGET_POT_EXHAUSTED));
        self::assertSame(1, $schedulesService->refundedOccurrencesCount);
    }

    public function testARunStillInProgressIsSkippedWithTheApplicationsWord(): void
    {
        $runningConversation = new AIConversation();
        $runningConversation->runState = AIConversationRunState::RUNNING;
        $this->replaceService(AIConversationsService::class, new ConversationsServiceWithScheduleRunDouble($runningConversation));
        $schedulesService = new SchedulesServiceWithOwnPotAndRunWordsDouble();

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule($this->buildRecurringScheduleOfSpace());

        self::assertSame('SHOP_RUN_STILL_BUSY', $occurrenceSpawn->skipReason);
        self::assertTrue($schedulesService->isSkipReasonOfPreviousRunInProgress((string)$occurrenceSpawn->skipReason));
    }

    protected function buildRecurringScheduleOfSpace(): AIConversationSchedule
    {
        $schedule = new AIConversationSchedule();
        $schedule->id = 4410;
        $schedule->accountId = 34;
        $schedule->agentSpaceId = 7;
        $owningAccount = Account::newInstance();
        $owningAccount->id = 34;
        $schedule->account = $owningAccount;
        return $schedule;
    }
}
