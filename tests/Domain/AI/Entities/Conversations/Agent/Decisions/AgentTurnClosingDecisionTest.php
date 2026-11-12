<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnClosingDecision;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The turn-closing consequence: only a confident announce verdict bounces; applied only when the decision model decided. */
class AgentTurnClosingDecisionTest extends UnitTestCase
{
    public function testOnlyAConfidentAnnounceVerdictBounces(): void
    {
        self::assertTrue(AgentTurnClosingDecision::resolveWouldBounce(0.94));
        self::assertTrue(AgentTurnClosingDecision::resolveWouldBounce(0.8));
        self::assertFalse(AgentTurnClosingDecision::resolveWouldBounce(0.79));
        self::assertFalse(AgentTurnClosingDecision::resolveWouldBounce(0.03));
        self::assertFalse(AgentTurnClosingDecision::resolveWouldBounce(null));
    }

    public function testOnlyADecisionModelStampIsApplied(): void
    {
        $decision = new AgentTurnClosingDecision();
        $decision->wouldBounce = true;
        $decision->decidedBy = AgentTurnClosingDecision::DECIDED_BY_SHADOW;
        self::assertFalse($decision->isApplied());
        $decision->decidedBy = AgentTurnClosingDecision::FALLBACK_TRANSPORT;
        self::assertFalse($decision->isApplied());
        $decision->decidedBy = AgentTurnClosingDecision::DECIDED_BY_DECISION_MODEL;
        self::assertTrue($decision->isApplied());
    }

    public function testTheSliceRoundTrips(): void
    {
        $decision = new AgentTurnClosingDecision();
        $decision->ownerMessageIndex = 17;
        $decision->decidedAt = '2026-09-22 10:00:00';
        $decision->decidedBy = AgentTurnClosingDecision::DECIDED_BY_DECISION_MODEL;
        $decision->announcesInsteadOfDeliversProbability = 0.94;
        $decision->reportsCompletedWritesProbability = 0.01;
        $decision->wouldBounce = true;
        $decision->openWorkItemCount = 3;
        $decision->answerLength = 210;
        $decision->modelVersion = 'typesafe/jev-1.13-20260917';
        $decision->turnClosingMode = 'decisionModel';
        $decision->fallbackReason = null;
        $decision->bounced = true;
        $restored = new AgentTurnClosingDecision();
        $restored->mapFromRepository(json_decode((string)json_encode($decision->mapToRepository()), true));
        self::assertSame($decision->mapToRepository(), $restored->mapToRepository());
        $empty = new AgentTurnClosingDecision();
        $empty->mapFromRepository(null);
        self::assertNull($empty->ownerMessageIndex);
        self::assertFalse($empty->wouldBounce);
        self::assertSame(0, $empty->openWorkItemCount);
        self::assertNull($empty->turnClosingMode, 'a slice from before plan 75 has no turn-closing mode');
        self::assertFalse($empty->bounced);
    }

    public function testARefusedCallIsItsOwnRecordedNonVerdict(): void
    {
        // the budget / envelope / scope refusal used to write no slice at all; now it is stamped like every
        // other non-verdict and never applied.
        $decision = new AgentTurnClosingDecision();
        $decision->decidedBy = AgentTurnClosingDecision::FALLBACK_REFUSED;
        $decision->wouldBounce = false;
        $decision->turnClosingMode = 'decisionModel';
        $decision->fallbackReason = 'refused';
        self::assertFalse($decision->isApplied());
        self::assertStringStartsWith('fallback:', AgentTurnClosingDecision::FALLBACK_REFUSED);
        $restored = new AgentTurnClosingDecision();
        $restored->mapFromRepository($decision->mapToRepository());
        self::assertSame('refused', $restored->fallbackReason);
        self::assertSame(AgentTurnClosingDecision::FALLBACK_REFUSED, $restored->decidedBy);
    }
}
