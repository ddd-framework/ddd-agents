<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationResumeCondition;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The fork-join wake predicates — the in-memory mirror of the settle SQL's WHERE. These decide whether the
 * parent wakes given the committed child counts; they must be exact (an off-by-one settles early or hangs).
 */
class AIConversationDelegationBatchJoinTest extends UnitTestCase
{
    public function testAllConditionSettlesOnlyWhenEveryChildIsTerminal(): void
    {
        $batch = $this->batch(AIConversationDelegationResumeCondition::ALL, expected: 3);
        self::assertNull($batch->effectiveQuorum(), 'ALL has no quorum');
        self::assertFalse($batch->isJoinSatisfiedByCounts(2, 0), '2 done of 3 → wait');
        self::assertFalse($batch->isJoinSatisfiedByCounts(1, 1), '1 done + 1 failed of 3 → wait');
        self::assertTrue($batch->isJoinSatisfiedByCounts(3, 0), 'all 3 done → settle');
        self::assertTrue($batch->isJoinSatisfiedByCounts(1, 2), '1 done + 2 failed (all terminal) → settle');
        self::assertFalse($batch->isQuorumUnreachable(3), 'ALL never fail-fasts');
    }

    public function testQuorumSettlesOnThresholdAndFailFastsWhenUnreachable(): void
    {
        $batch = $this->batch(AIConversationDelegationResumeCondition::QUORUM, expected: 4, quorum: 2);
        self::assertSame(2, $batch->effectiveQuorum());
        self::assertFalse($batch->isJoinSatisfiedByCounts(1, 0), '1 success < quorum 2 → wait');
        self::assertTrue($batch->isJoinSatisfiedByCounts(2, 0), 'quorum 2 reached → settle');
        self::assertFalse($batch->isQuorumUnreachable(2), '4-2=2 still ≥ quorum 2 → reachable');
        self::assertTrue($batch->isQuorumUnreachable(3), '4-3=1 < quorum 2 → unreachable, fail-fast');
        self::assertTrue($batch->isJoinSatisfiedByCounts(0, 3), 'quorum unreachable → settle (failed)');
        self::assertFalse($batch->isJoinSatisfiedByCounts(1, 1), '1<2 and 4-1=3≥2 → neither → wait');
    }

    public function testAnyIsQuorumOfOneIncludingAllFailFailFast(): void
    {
        $batch = $this->batch(AIConversationDelegationResumeCondition::ANY, expected: 3);
        self::assertSame(1, $batch->effectiveQuorum(), 'ANY ≡ quorum of 1');
        self::assertTrue($batch->isJoinSatisfiedByCounts(1, 0), 'first success → settle');
        self::assertFalse($batch->isJoinSatisfiedByCounts(0, 2), '0 done, 3-2=1 ≥ 1 → still possible → wait');
        self::assertTrue($batch->isJoinSatisfiedByCounts(0, 3), 'all 3 failed → 3-3=0 < 1 → fail-fast (no hang)');
    }

    public function testBackgroundSharesAllJoinMathAndNeverFailFasts(): void
    {
        $batch = $this->batch(AIConversationDelegationResumeCondition::BACKGROUND, expected: 3);
        self::assertTrue($batch->isAllCondition(), 'BACKGROUND rides the ALL join (in-memory mirror of the settle SQL isAll branch)');
        self::assertNull($batch->effectiveQuorum(), 'BACKGROUND has no quorum');
        self::assertFalse($batch->isJoinSatisfiedByCounts(1, 0), 'first success must NOT settle (BACKGROUND is not ANY)');
        self::assertFalse($batch->isJoinSatisfiedByCounts(2, 0), '2 of 3 terminal → wait');
        self::assertTrue($batch->isJoinSatisfiedByCounts(2, 1), 'all 3 terminal (incl. a failure) → settle');
        self::assertTrue($batch->isJoinSatisfiedByCounts(3, 0), 'all 3 done → settle');
        self::assertFalse($batch->isQuorumUnreachable(3), 'BACKGROUND never fail-fasts');
    }

    public function testSettledAndFoldedFlags(): void
    {
        $batch = $this->batch(AIConversationDelegationResumeCondition::ALL, expected: 1);
        self::assertFalse($batch->isParentResumeConditionMet());
        self::assertFalse($batch->hasParentReadChildResults());
        $batch->settledAt = new \DDD\Infrastructure\Base\DateTime\DateTime();
        self::assertTrue($batch->isParentResumeConditionMet());
        $batch->foldedAt = new \DDD\Infrastructure\Base\DateTime\DateTime();
        self::assertTrue($batch->hasParentReadChildResults());
    }

    protected function batch(string $condition, int $expected, ?int $quorum = null): AIConversationDelegationBatch
    {
        /** @var AIConversationDelegationBatch $batch */
        $batch = self::instantiateWithoutConstructor(AIConversationDelegationBatch::class);
        $batch->delegateWakeCondition = $condition;
        $batch->expectedDelegationCount = $expected;
        $batch->quorumSuccessThreshold = $quorum;
        $batch->settledAt = null;
        $batch->foldedAt = null;
        return $batch;
    }
}
