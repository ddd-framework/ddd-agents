<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunRelease;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A run whose project or account no longer permits automated work is released without a retry — a
 * deleted project's run closes CANCELLED without a failed widget, a suspended scope fails like a budget stop.
 */
class AgentRunReleaseScopeNotEligibleTest extends UnitTestCase
{
    public function testAScopeGoneForGoodClosesTheRunCancelledWithoutARetry(): void
    {
        $runRelease = AgentRunRelease::afterScopeNotEligible(scopeGoneForGood: true, isDelegationChildInBatch: false);

        self::assertSame(AIConversationRunState::CANCELLED, $runRelease->runState);
        self::assertSame(0, $runRelease->resumeAttempts);
        self::assertNull($runRelease->agentTurnOwedAt, 'nothing is owed: the recovery cron never re-drives it');
        self::assertSame(AIConversationRealtimeSignalService::STATUS_IDLE, $runRelease->realtimeStatus, 'no failed widget; an open chat stops its running indicator');
        self::assertSame(AIConversationTurnEvent::OUTCOME_CANCELLED, $runRelease->turnEndOutcome, 'the turn end is recorded');
        self::assertFalse($runRelease->reFiresAfterRelease);
        self::assertFalse($runRelease->finishesIntoDelegationBatch);
    }

    public function testASuspendedScopeFailsWithoutARetry(): void
    {
        $runRelease = AgentRunRelease::afterScopeNotEligible(scopeGoneForGood: false, isDelegationChildInBatch: false);

        self::assertSame(AIConversationRunState::FAILED, $runRelease->runState);
        self::assertSame(0, $runRelease->resumeAttempts);
        self::assertNull($runRelease->agentTurnOwedAt);
        self::assertSame(AIConversationRealtimeSignalService::STATUS_FAILED, $runRelease->realtimeStatus);
    }

    public function testADelegationChildInABatchFinishesIntoItWhateverTheScope(): void
    {
        foreach ([true, false] as $scopeGoneForGood) {
            $runRelease = AgentRunRelease::afterScopeNotEligible($scopeGoneForGood, isDelegationChildInBatch: true);
            self::assertTrue($runRelease->finishesIntoDelegationBatch, 'the parent is never stranded');
            self::assertSame(0, $runRelease->resumeAttempts);
        }
    }
}
