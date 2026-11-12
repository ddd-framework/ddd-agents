<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Reads;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRun;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\Recovery\AIConversationStuckRunsReadService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\AIConversationPurposeOfAnApplicationWithAnIntake;
use DDD\Tests\Agents\Integration\Conversations\DelegationTreeDatabaseTestCase;

/**
 * The raw statements of {@see AIConversationStuckRunsReadService} against the database, on both engines: the parents of
 * the batches past their deadline and the row facts of every listed conversation.
 */
class AIConversationStuckRunsReadServiceDatabaseTest extends DelegationTreeDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testAHungRunAndAParentWithBatchesPastTheirDeadlineAreListedWithTheirRowFacts(): void
    {
        $hungHeartbeatAt = $this->formatMomentSecondsAgo(AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS + 100);
        $this->insertConversationOfThisEnvironment([
            'id' => self::PARENT_CONVERSATION_ID,
            'runState' => AIConversationRunState::RUNNING,
            'runToken' => 'token-parent',
            'runHeartbeatAt' => $hungHeartbeatAt,
            'purposeName' => AIConversationPurpose::SCHEDULE_RUN,
            'resumeAttempts' => 2,
        ]);
        $deadlinePassedAt = $this->formatMomentSecondsAgo(60);
        $this->insertBatch(['id' => 500, 'deadlineAt' => $deadlinePassedAt]);
        $this->insertBatch(['id' => 501, 'deadlineAt' => $deadlinePassedAt]);
        $this->insertBatch(['id' => 502]);
        $this->insertConversationOfThisEnvironment(['id' => 110, 'runState' => AIConversationRunState::IDLE]);

        $now = new DateTime();
        $stuckRuns = (new AIConversationStuckRunsReadService())->findStuckRuns($now);

        self::assertSame([self::PARENT_CONVERSATION_ID], $stuckRuns->getConversationIds());
        $stuckRun = $stuckRuns->findOrAddStuckRun(self::PARENT_CONVERSATION_ID);
        self::assertContains(AIConversationStuckRun::REASON_NO_HEARTBEAT, $stuckRun->reasons);
        self::assertContains(AIConversationStuckRun::REASON_DEADLINE_PASSED, $stuckRun->reasons);
        $passedDeadlineBatchIds = $stuckRun->passedDeadlineBatchIds;
        sort($passedDeadlineBatchIds);
        self::assertSame([500, 501], $passedDeadlineBatchIds, 'the batch before its deadline is not listed');
        self::assertSame(AIConversationRunState::RUNNING, $stuckRun->runState);
        self::assertSame(AIConversationPurpose::SCHEDULE_RUN, $stuckRun->purposeName);
        self::assertSame(2, $stuckRun->resumeAttempts);
        self::assertSame($hungHeartbeatAt, $stuckRun->runHeartbeatAt?->format('Y-m-d H:i:s'));
        self::assertGreaterThanOrEqual(AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS + 100, $stuckRun->heartbeatAgeSeconds);
        self::assertNull($stuckRun->accountId);
        self::assertNull($stuckRun->rootConversationId);
    }

    public function testAQuietIdleConversationIsListedOnlyForAPurposeTheApplicationClosesWithoutAnOwnerTurn(): void
    {
        $quietHeartbeatAt = $this->formatMomentSecondsAgo(AIConversationStuckRunsReadService::PURPOSE_SETTLE_QUIET_SECONDS + 100);
        $this->insertConversationOfThisEnvironment([
            'id' => 120,
            'runState' => AIConversationRunState::IDLE,
            'purposeName' => AIConversationPurposeOfAnApplicationWithAnIntake::INTAKE_CHAT,
            'runHeartbeatAt' => $quietHeartbeatAt,
        ]);
        $this->insertConversationOfThisEnvironment([
            'id' => 121,
            'runState' => AIConversationRunState::IDLE,
            'purposeName' => AIConversationPurposeOfAnApplicationWithAnIntake::INTAKE_CALL,
            'runHeartbeatAt' => $quietHeartbeatAt,
        ]);
        $frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        try {
            StaticRegistry::$frameworkToAppClassNames[AIConversationPurpose::class] = AIConversationPurpose::class;
            self::assertSame([], (new AIConversationStuckRunsReadService())->findStuckRuns(new DateTime())->getConversationIds());

            StaticRegistry::$frameworkToAppClassNames[AIConversationPurpose::class] = AIConversationPurposeOfAnApplicationWithAnIntake::class;
            $stuckRuns = (new AIConversationStuckRunsReadService())->findStuckRuns(new DateTime());
        } finally {
            StaticRegistry::$frameworkToAppClassNames = $frameworkToAppClassNamesBefore;
        }

        self::assertSame([120], $stuckRuns->getConversationIds());
        self::assertSame([AIConversationStuckRun::REASON_QUIET_IDLE_PURPOSED], $stuckRuns->findOrAddStuckRun(120)->reasons);
    }
}
