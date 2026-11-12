<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecision;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The outcome records the plan-dynamics site writes are assembled by the decision itself.
 */
class AgentPlanDynamicsDecisionOutcomeTest extends UnitTestCase
{
    public function testTheStartOutcomeCarriesTheDirectiveOnlyWhenApplied(): void
    {
        $decision = AgentPlanDynamicsDecision::build(5);
        $decision->needsWorkingPlanProbability = 0.95;
        $decision->band = AgentPlanDynamicsDecision::resolveBand(0.95);

        $decision->decidedBy = AgentPlanDynamicsDecision::DECIDED_BY_SHADOW;
        $shadowOutcome = $decision->buildStartOutcome('shadow', 0);
        self::assertFalse($shadowOutcome['applied']);
        self::assertNull($shadowOutcome['directive']);

        $decision->decidedBy = AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL;
        $appliedOutcome = $decision->buildStartOutcome('on', 0);
        self::assertTrue($appliedOutcome['applied']);
        self::assertSame($decision->toDirectiveText(0), $appliedOutcome['directive']);
        self::assertSame(AgentPlanDynamicsDecision::BAND_REQUIRED, $appliedOutcome['band']);
        self::assertSame(5, $decision->ownerMessageIndex);
        self::assertNotNull($decision->decidedAt);
    }
}
