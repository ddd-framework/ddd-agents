<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;

/** Overrides the two side-effecting points: the Argus judgment call and the status persist. */
class DoneCriteriaJudgingSchedulesServiceStub extends AIConversationSchedulesService
{
    public ?bool $stubbedVerdict = null;

    public int $judgmentCallCount = 0;

    public ?string $lastJudgedRunOutcomeText = null;

    /** @var string[] */
    public array $transitionedStatuses = [];

    protected function judgeDoneCriteriaFulfilled(AIConversationSchedule $schedule, string $boundedRunOutcomeText): ?bool
    {
        $this->judgmentCallCount++;
        $this->lastJudgedRunOutcomeText = $boundedRunOutcomeText;
        return $this->stubbedVerdict;
    }

    public function transitionStatus(AIConversationSchedule $schedule, string $status): void
    {
        $this->transitionedStatuses[] = $status;
        $schedule->status = $status;
    }
}
