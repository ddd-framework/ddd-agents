<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The doneCriteria judgment gate at a schedule run's terminal:
 * {@see AIConversationSchedulesService::transitionToDoneWhenDoneCriteriaFulfilled()} transitions ACTIVE → DONE
 * ONLY on a parseable fulfilled=true verdict; every guard (no criteria, non-ACTIVE, empty outcome) short-circuits
 * WITHOUT a model call, and a failed judgment (null) changes nothing. The model call and the status persist are
 * the two override points ({@see DoneCriteriaJudgingSchedulesServiceStub}).
 */
class AIConversationSchedulesServiceDoneCriteriaTest extends UnitTestCase
{
    public function testFulfilledVerdictTransitionsTheActiveScheduleToDone(): void
    {
        $schedulesServiceStub = $this->schedulesServiceStub(verdict: true);
        $schedule = $this->schedule(doneCriteria: 'profile completeness reaches 100%');

        self::assertTrue($schedulesServiceStub->transitionToDoneWhenDoneCriteriaFulfilled($schedule, 'Profile is now 100% complete.'));
        self::assertSame([AIConversationScheduleStatus::DONE], $schedulesServiceStub->transitionedStatuses);
        self::assertSame(1, $schedulesServiceStub->judgmentCallCount);
    }

    public function testNotFulfilledAndFailedVerdictsChangeNothing(): void
    {
        $notFulfilledStub = $this->schedulesServiceStub(verdict: false);
        $schedule = $this->schedule(doneCriteria: 'profile completeness reaches 100%');
        self::assertFalse($notFulfilledStub->transitionToDoneWhenDoneCriteriaFulfilled($schedule, 'Posted one update; completeness at 80%.'));
        self::assertSame([], $notFulfilledStub->transitionedStatuses);

        // A FAILED/unparseable judgment (null) must be indistinguishable from "keep firing" — never a transition.
        $failedJudgmentStub = $this->schedulesServiceStub(verdict: null);
        self::assertFalse($failedJudgmentStub->transitionToDoneWhenDoneCriteriaFulfilled($schedule, 'Posted one update.'));
        self::assertSame([], $failedJudgmentStub->transitionedStatuses);
        self::assertSame(1, $failedJudgmentStub->judgmentCallCount);
    }

    public function testGuardsShortCircuitWithoutAModelCall(): void
    {
        $schedulesServiceStub = $this->schedulesServiceStub(verdict: true);

        // No doneCriteria → nothing to judge.
        $withoutCriteria = $this->schedule(doneCriteria: null);
        self::assertFalse($schedulesServiceStub->transitionToDoneWhenDoneCriteriaFulfilled($withoutCriteria, 'done everything'));

        // Not ACTIVE (paused/cancelled between fire and terminal) → the pause/cancel must stand.
        $pausedSchedule = $this->schedule(doneCriteria: 'goal', status: AIConversationScheduleStatus::PAUSED);
        self::assertFalse($schedulesServiceStub->transitionToDoneWhenDoneCriteriaFulfilled($pausedSchedule, 'done everything'));

        // Empty run outcome → no evidence to judge.
        $activeSchedule = $this->schedule(doneCriteria: 'goal');
        self::assertFalse($schedulesServiceStub->transitionToDoneWhenDoneCriteriaFulfilled($activeSchedule, '   '));

        self::assertSame(0, $schedulesServiceStub->judgmentCallCount);
        self::assertSame([], $schedulesServiceStub->transitionedStatuses);
    }

    public function testRunOutcomeTextIsBoundedBeforeTheJudgmentCall(): void
    {
        $schedulesServiceStub = $this->schedulesServiceStub(verdict: false);
        $schedule = $this->schedule(doneCriteria: 'goal');
        $oversizedRunOutcomeText = str_repeat('x', AIConversationSchedulesService::DONE_CRITERIA_JUDGMENT_OUTCOME_MAX_CHARS + 500);

        $schedulesServiceStub->transitionToDoneWhenDoneCriteriaFulfilled($schedule, $oversizedRunOutcomeText);

        self::assertSame(
            AIConversationSchedulesService::DONE_CRITERIA_JUDGMENT_OUTCOME_MAX_CHARS,
            mb_strlen((string)$schedulesServiceStub->lastJudgedRunOutcomeText)
        );
    }

    protected function schedulesServiceStub(?bool $verdict): DoneCriteriaJudgingSchedulesServiceStub
    {
        /** @var DoneCriteriaJudgingSchedulesServiceStub $schedulesServiceStub */
        $schedulesServiceStub = self::instantiateWithoutConstructor(DoneCriteriaJudgingSchedulesServiceStub::class);
        $schedulesServiceStub->stubbedVerdict = $verdict;
        $schedulesServiceStub->transitionedStatuses = [];
        $schedulesServiceStub->judgmentCallCount = 0;
        return $schedulesServiceStub;
    }

    protected function schedule(?string $doneCriteria, string $status = AIConversationScheduleStatus::ACTIVE): AIConversationSchedule
    {
        /** @var AIConversationSchedule $schedule */
        $schedule = self::instantiateWithoutConstructor(AIConversationSchedule::class);
        $schedule->id = 7;
        $schedule->task = 'Post a weekly update until the profile is complete.';
        $schedule->doneCriteria = $doneCriteria;
        $schedule->status = $status;
        return $schedule;
    }
}
