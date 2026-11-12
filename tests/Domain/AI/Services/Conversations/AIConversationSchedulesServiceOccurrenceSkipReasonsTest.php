<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * Why a due occurrence of a schedule starts no run when its space is gone or its space is not eligible: the word the
 * service answers with names the agent space, and an application whose service redeclares the two constants is
 * answered with its own words.
 */
class AIConversationSchedulesServiceOccurrenceSkipReasonsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceService(AIConversationsService::class, new AIConversationsServiceWithoutEarlierScheduleRuns());
        $this->replaceService(AgentAutomationEligibilityService::class, new AgentAutomationEligibilityServiceWithEverySpaceSuspended());
        StaticRegistry::$entityServices = [];
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$entityServices = [];
    }

    public function testAScheduleWhoseSpaceIsGoneIsClosedAndSkipped(): void
    {
        $schedulesService = new AIConversationSchedulesServiceRecordingScheduleWrites();

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule($this->buildRecurringScheduleOfSpace(7));

        self::assertNull($occurrenceSpawn->runConversation);
        self::assertSame('AGENT_SPACE_MISSING', $occurrenceSpawn->skipReason);
        self::assertSame(AIConversationSchedulesService::OCCURRENCE_SKIP_REASON_AGENT_SPACE_MISSING, $occurrenceSpawn->skipReason);
        self::assertSame([[AIConversationScheduleStatus::CANCELLED, 'AGENT_SPACE_ENDED']], $schedulesService->recordedAutomationStateTransitions);
        self::assertSame(0, $schedulesService->refundedOccurrencesCount);
    }

    public function testAScheduleOfASpaceThatIsNotEligibleIsSkippedAndItsOccurrenceRefunded(): void
    {
        $schedulesService = new AIConversationSchedulesServiceRecordingScheduleWrites();
        $schedulesService->agentSpaceOfEverySchedule = $this->buildStoredAgentSpace(7);

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule($this->buildRecurringScheduleOfSpace(7));

        self::assertNull($occurrenceSpawn->runConversation);
        self::assertSame('AGENT_SPACE_NOT_ELIGIBLE', $occurrenceSpawn->skipReason);
        self::assertSame(AIConversationSchedulesService::OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE, $occurrenceSpawn->skipReason);
        self::assertTrue($occurrenceSpawn->skipEligibility->isSuspended());
        self::assertSame([], $schedulesService->recordedAutomationStateTransitions);
        self::assertSame(1, $schedulesService->refundedOccurrencesCount);
    }

    public function testAOneTimeScheduleOfASpaceThatIsNotEligibleIsSkippedWithoutARefund(): void
    {
        $schedulesService = new AIConversationSchedulesServiceRecordingScheduleWrites();
        $schedulesService->agentSpaceOfEverySchedule = $this->buildStoredAgentSpace(7);
        $oneTimeSchedule = $this->buildRecurringScheduleOfSpace(7);
        $oneTimeSchedule->oneTimeAt = new DateTime('2026-10-01 09:00:00');

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule($oneTimeSchedule);

        self::assertSame('AGENT_SPACE_NOT_ELIGIBLE', $occurrenceSpawn->skipReason);
        self::assertSame(0, $schedulesService->refundedOccurrencesCount);
    }

    public function testAnApplicationsServiceAnswersWithItsOwnWords(): void
    {
        $schedulesServiceOfAnApplication = new AIConversationSchedulesServiceOfAnApplicationWithWorkspaces();

        $spawnForAMissingSpace = $schedulesServiceOfAnApplication->spawnRunConversationForClaimedSchedule($this->buildRecurringScheduleOfSpace(7));
        $schedulesServiceOfAnApplication->agentSpaceOfEverySchedule = $this->buildStoredAgentSpace(7);
        $spawnForASuspendedSpace = $schedulesServiceOfAnApplication->spawnRunConversationForClaimedSchedule($this->buildRecurringScheduleOfSpace(7));

        self::assertSame('WORKSPACE_MISSING', $spawnForAMissingSpace->skipReason);
        self::assertSame('WORKSPACE_NOT_ELIGIBLE', $spawnForASuspendedSpace->skipReason);
    }

    protected function buildRecurringScheduleOfSpace(int $agentSpaceId): AIConversationSchedule
    {
        $schedule = new AIConversationSchedule();
        $schedule->id = 4410;
        $schedule->accountId = 34;
        $schedule->agentSpaceId = $agentSpaceId;
        return $schedule;
    }

    protected function buildStoredAgentSpace(int $id): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $id;
        return $agentSpace;
    }
}

/** The schedules service with the space of a schedule set by the test and its writes kept in memory. */
class AIConversationSchedulesServiceRecordingScheduleWrites extends AIConversationSchedulesService
{
    /** @var AgentSpace|null The space every schedule is answered with; null = the space is gone. */
    public ?AgentSpace $agentSpaceOfEverySchedule = null;

    /** @var array<int, array{0: string, 1: string|null}> Status and stamped reason of every automation-state transition. */
    public array $recordedAutomationStateTransitions = [];

    public int $refundedOccurrencesCount = 0;

    protected function findAgentSpaceOfSchedule(AIConversationSchedule $schedule): ?AgentSpace
    {
        return $this->agentSpaceOfEverySchedule;
    }

    public function transitionStatusForAutomationState(AIConversationSchedule $schedule, string $status, ?string $stateReason): void
    {
        $this->recordedAutomationStateTransitions[] = [$status, $stateReason];
    }

    public function refundClaimedOccurrence(AIConversationSchedule $schedule, bool $reArmNow): void
    {
        $this->refundedOccurrencesCount++;
    }
}

/** The schedules service of an application whose scope below the account is a workspace. */
class AIConversationSchedulesServiceOfAnApplicationWithWorkspaces extends AIConversationSchedulesServiceRecordingScheduleWrites
{
    public const string OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE = 'WORKSPACE_NOT_ELIGIBLE';

    public const string OCCURRENCE_SKIP_REASON_AGENT_SPACE_MISSING = 'WORKSPACE_MISSING';
}

/** Conversations in which no schedule has an earlier run. */
class AIConversationsServiceWithoutEarlierScheduleRuns extends AIConversationsService
{
    public function findNewestScheduleRunConversation(int $scheduleId): ?AIConversation
    {
        return null;
    }
}

/** An eligibility in which every space is suspended and every account eligible. */
class AgentAutomationEligibilityServiceWithEverySpaceSuspended extends AgentAutomationEligibilityService
{
    public function findEligibilityForAgentSpace(AgentSpace $agentSpace, bool $useCache = true): AgentAutomationEligibility
    {
        $eligibility = AgentAutomationEligibility::eligible();
        $eligibility->state = AgentAutomationEligibility::STATE_SUSPENDED;
        $eligibility->signalSource = AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE;
        $eligibility->resumesAutomatically = true;
        return $eligibility;
    }

    public function refusesAutomation(AgentAutomationEligibility $eligibility, string $operation, AgentEligibilityLogContext $logContext): bool
    {
        return !$eligibility->isEligible();
    }
}
