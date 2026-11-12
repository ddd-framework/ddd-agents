<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Reads;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRun;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRuns;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRunsFilter;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposedRunStallReason;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposedRunsReadService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw statements of {@see AIConversationPurposedRunsReadService} against the database, on both engines: the count
 * and the page of a purpose's runs with the stall verdict of each (failed, hung, owed turn, owed report, open plan
 * unanswered or the owner away), the eval marker, the plans and environments of the page, and the criteria review
 * over the newest runs of one purpose.
 */
class AIConversationPurposedRunsReadServiceDatabaseTest extends DatabaseTestCase
{
    protected const int RUN_WITH_PLAN_ID = 606;

    protected const int EVAL_RUN_ID = 607;

    protected DateTime $now;

    protected AIConversationPurposedRunsReadService $purposedRunsReadService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->now = new DateTime();
        $this->purposedRunsReadService = new AIConversationPurposedRunsReadService();
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testAPageOfRunsCarriesTheStallVerdictTheEvalMarkerThePlanAndTheEnvironment(): void
    {
        $this->insertRunsOfEveryStallReason();

        $purposedRuns = $this->purposedRunsReadService->findPurposedRuns($this->buildFilter(), $this->now);

        self::assertSame(7, $purposedRuns->totalCount, 'no eval run, no run of another purpose, none before the window');
        self::assertSame([606, 605, 604, 603, 602, 601, 600], $this->getConversationIds($purposedRuns), 'newest first');
        self::assertSame([
            606 => null,
            605 => AIConversationPurposedRunStallReason::TURN_OWED,
            604 => AIConversationPurposedRunStallReason::REPORT_OWED,
            603 => AIConversationPurposedRunStallReason::OPEN_PLAN_OWNER_AWAY,
            602 => AIConversationPurposedRunStallReason::OPEN_PLAN_UNANSWERED,
            601 => AIConversationPurposedRunStallReason::HUNG,
            600 => AIConversationPurposedRunStallReason::FAILED,
        ], $this->getStalledReasonsByConversationId($purposedRuns));
        $runWithPlan = $this->findRun($purposedRuns, self::RUN_WITH_PLAN_ID);
        self::assertFalse($runWithPlan->isEvalRun);
        self::assertSame(AIConversationsService::currentEnvironment(), $runWithPlan->environment);
        self::assertSame([1, 1], [$runWithPlan->planDone, $runWithPlan->planOpen]);
        self::assertSame(AIConversationRunState::COMPLETED, $runWithPlan->runState);
        self::assertNull($this->findRun($purposedRuns, 605)->environment, 'a stored JSON null environment reads as none');
        self::assertNull($this->findRun($purposedRuns, 605)->planDone);

        $firstPage = $this->purposedRunsReadService->findPurposedRuns($this->buildFilter(top: 2, skip: 1), $this->now);
        self::assertSame(7, $firstPage->totalCount);
        self::assertSame([605, 604], $this->getConversationIds($firstPage));
    }

    public function testTheWindowIsFilteredByStallStateEvalAndAccount(): void
    {
        $this->insertRunsOfEveryStallReason();

        $stalledRuns = $this->purposedRunsReadService->findPurposedRuns($this->buildFilter(onlyStalled: true), $this->now);
        self::assertSame(6, $stalledRuns->totalCount);
        self::assertNotContains(self::RUN_WITH_PLAN_ID, $this->getConversationIds($stalledRuns));

        $runsWithEval = $this->purposedRunsReadService->findPurposedRuns($this->buildFilter(includeEvalRuns: true), $this->now);
        self::assertSame(8, $runsWithEval->totalCount);
        self::assertTrue($this->findRun($runsWithEval, self::EVAL_RUN_ID)->isEvalRun);

        $failedRuns = $this->purposedRunsReadService->findPurposedRuns($this->buildFilter(runStates: [AIConversationRunState::FAILED]), $this->now);
        self::assertSame([600], $this->getConversationIds($failedRuns));

        $runsOfAnotherAccount = $this->purposedRunsReadService->findPurposedRuns($this->buildFilter(accountId: 91), $this->now);
        self::assertSame(0, $runsOfAnotherAccount->totalCount);
    }

    public function testTheCriteriaReviewCountsTheItemsOfThePlansOfThePurposesRuns(): void
    {
        $this->insertRunsOfEveryStallReason();

        $criteriaReview = $this->purposedRunsReadService->reviewPurposeCriteria($this->buildFilter(), $this->now);

        self::assertSame(7, $criteriaReview->totalRunCount);
        self::assertSame(7, $criteriaReview->reviewedRunCount);
        self::assertSame(6, $criteriaReview->runsWithoutPlan);
        self::assertFalse($criteriaReview->reviewCapReached);
        $openCountsByCriterionKey = [];
        foreach ($criteriaReview->getReviewsByOpenCountDescending() as $criterionReview) {
            $openCountsByCriterionKey[$criterionReview->doneCriterionKey] = [$criterionReview->done, $criterionReview->open];
        }
        ksort($openCountsByCriterionKey);
        self::assertSame(['probe.record' => [1, 0], 'probe.report' => [0, 1]], $openCountsByCriterionKey);
    }

    /**
     * Seven runs of `Schedule.Run` in the window, one for each stall verdict and one without; an eval run, a run of
     * another purpose and a run before the window.
     */
    protected function insertRunsOfEveryStallReason(): void
    {
        $quietSince = $this->formatMomentSecondsAgo(7200);
        $this->insertRun(600, 360, ['runState' => AIConversationRunState::FAILED]);
        $this->insertRun(601, 300, [
            'runState' => AIConversationRunState::RUNNING,
            'runHeartbeatAt' => $this->formatMomentSecondsAgo(AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS + 100),
        ]);
        $this->insertRun(602, 240, ['runState' => AIConversationRunState::IDLE, 'taskPlanOpenAt' => $quietSince, 'runHeartbeatAt' => $quietSince, 'lastInboundMessageAt' => $quietSince]);
        $this->insertRun(603, 180, ['runState' => AIConversationRunState::IDLE, 'taskPlanOpenAt' => $quietSince, 'runHeartbeatAt' => $quietSince, 'lastInboundMessageAt' => $quietSince]);
        $this->insertRun(604, 120, ['runState' => AIConversationRunState::COMPLETED, 'reportOwedAt' => $quietSince]);
        $this->insertRun(605, 90, [
            'runState' => AIConversationRunState::IDLE,
            'agentTurnOwedAt' => $this->formatMomentSecondsAgo(AIConversationsService::AGENT_TURN_OWED_QUIET_SECONDS + 100),
            'agentWorkingState' => json_encode(['environment' => null]),
        ]);
        $this->insertRun(self::RUN_WITH_PLAN_ID, 60, [
            'runState' => AIConversationRunState::COMPLETED,
            'agentWorkingState' => json_encode(['environment' => AIConversationsService::currentEnvironment(), 'taskPlan' => $this->buildStoredTaskPlan()]),
        ]);
        $this->insertRun(self::EVAL_RUN_ID, 30, ['runState' => AIConversationRunState::COMPLETED, 'runSettings' => json_encode(['evalRun' => true])]);
        $this->insertRun(608, 20, ['purposeName' => AIConversationPurpose::SUPERVISOR_CHECKPOINT, 'runState' => AIConversationRunState::FAILED]);
        $this->insertRun(609, 10 * 86400, ['runState' => AIConversationRunState::FAILED]);
        // the newest text of 602 is the owner's, of 603 the agent's
        $this->insertTextMessage(602, 0, AIConversationMessage::ROLE_ASSISTANT);
        $this->insertTextMessage(602, 1, AIConversationMessage::ROLE_USER);
        $this->insertTextMessage(603, 0, AIConversationMessage::ROLE_USER);
        $this->insertTextMessage(603, 1, AIConversationMessage::ROLE_ASSISTANT);
    }

    /** @param array<string, mixed> $columnValues */
    protected function insertRun(int $conversationId, int $createdSecondsAgo, array $columnValues): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), $columnValues + [
            'id' => $conversationId,
            'purposeName' => AIConversationPurpose::SCHEDULE_RUN,
            'created' => $this->formatMomentSecondsAgo($createdSecondsAgo),
        ]);
    }

    protected function insertTextMessage(int $conversationId, int $indexInConversation, string $role): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'role' => $role,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'message' => '',
            'created' => $this->formatMomentSecondsAgo(7200 - $indexInConversation),
        ]);
    }

    /** @return array<string, mixed> A stored plan of two criterion leaves, the first done */
    protected function buildStoredTaskPlan(): array
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Record', 'doneCriterion' => 'probe.record'],
            ['text' => 'Report', 'doneCriterion' => 'probe.report'],
        ]);
        $taskPlan->items->getElements()[0]->status = TaskPlanItem::STATUS_DONE;
        return $taskPlan->mapToRepository();
    }

    /** @param string[] $runStates */
    protected function buildFilter(
        array $runStates = [],
        ?int $accountId = null,
        bool $onlyStalled = false,
        bool $includeEvalRuns = false,
        ?int $top = null,
        ?int $skip = null
    ): AIConversationPurposedRunsFilter {
        return AIConversationPurposedRunsFilter::fromRequest(
            purposeNames: [AIConversationPurpose::SCHEDULE_RUN],
            createdFrom: null,
            createdTo: null,
            windowDays: 7,
            runStates: $runStates,
            accountId: $accountId,
            onlyStalled: $onlyStalled,
            includeEvalRuns: $includeEvalRuns,
            top: $top,
            skip: $skip,
            now: $this->now
        );
    }

    protected function formatMomentSecondsAgo(int $seconds): string
    {
        return (clone $this->now)->modify('-' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }

    /** @return int[] */
    protected function getConversationIds(AIConversationPurposedRuns $purposedRuns): array
    {
        return array_map(static fn (AIConversationPurposedRun $purposedRun): int => $purposedRun->aiConversationId, $purposedRuns->getElements());
    }

    /** @return array<int, string|null> */
    protected function getStalledReasonsByConversationId(AIConversationPurposedRuns $purposedRuns): array
    {
        $stalledReasonsByConversationId = [];
        foreach ($purposedRuns->getElements() as $purposedRun) {
            $stalledReasonsByConversationId[$purposedRun->aiConversationId] = $purposedRun->stalledReason;
        }
        return $stalledReasonsByConversationId;
    }

    protected function findRun(AIConversationPurposedRuns $purposedRuns, int $conversationId): AIConversationPurposedRun
    {
        foreach ($purposedRuns->getElements() as $purposedRun) {
            if ($purposedRun->aiConversationId === $conversationId) {
                return $purposedRun;
            }
        }
        self::fail('run ' . $conversationId . ' is not on the page');
    }
}
