<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortLimits;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The pure reasoning-effort decision: the PHP precondition (word floor, identity reply, disagreement,
 * failure), the raise-only mapping with the two floors, the pin and the ceiling, and the slice round trip.
 */
class AgentReasoningEffortDecisionTest extends UnitTestCase
{
    public function testThePreconditionAdmitsOnlyWhatCarriesReasoningWork(): void
    {
        self::assertSame(AgentReasoningEffortDecision::PRECONDITION_NONE, AgentReasoningEffortDecision::resolvePreconditionReason('ja', false, false, false));
        self::assertSame(AgentReasoningEffortDecision::PRECONDITION_NONE, AgentReasoningEffortDecision::resolvePreconditionReason('Nee, das bin ich nicht 😅 Falsches Business!', false, false, false), 'eight words alone stay low');
        self::assertSame(AgentReasoningEffortDecision::PRECONDITION_IDENTITY_REPLY, AgentReasoningEffortDecision::resolvePreconditionReason('Nee, das bin ich nicht 😅 Falsches Business!', true, false, false), 'a reply to an identity proposal is admitted whatever its length');
        self::assertSame(AgentReasoningEffortDecision::PRECONDITION_DISAGREEMENT, AgentReasoningEffortDecision::resolvePreconditionReason('ok', false, true, false));
        self::assertSame(AgentReasoningEffortDecision::PRECONDITION_RECENT_FAILURE, AgentReasoningEffortDecision::resolvePreconditionReason('weiter', false, false, true));
        self::assertSame(
            AgentReasoningEffortDecision::PRECONDITION_WORD_FLOOR,
            AgentReasoningEffortDecision::resolvePreconditionReason('Google verbinden — mach ich später. Aber was machen wir bis dahin konkret? Ihr dreht euch im Kreis.', false, false, false)
        );
    }

    public function testWordsAreTokensWithALetterOrDigit(): void
    {
        self::assertSame(0, AgentReasoningEffortDecision::countWords('  — 😅 '));
        self::assertSame(3, AgentReasoningEffortDecision::countWords("Website ist\n falsch —"));
        self::assertSame(2, AgentReasoningEffortDecision::countWords('junge.de, nicht'));
    }

    public function testTheMappingRaisesOnlyAboveTheFloorsAndHighWinsOverMedium(): void
    {
        $low = AgentDecisionModelSettings::REASONING_EFFORT_LOW;
        self::assertSame('low', AgentReasoningEffortDecision::resolveEffortFromAnswers(0.1, 0.2, 0.3, null, $low));
        self::assertSame('low', AgentReasoningEffortDecision::resolveEffortFromAnswers(0.84, 0.84, 0.89, 0.89, $low), 'just under both floors');
        self::assertSame('high', AgentReasoningEffortDecision::resolveEffortFromAnswers(0.85, 0.0, 0.0, null, $low));
        self::assertSame('high', AgentReasoningEffortDecision::resolveEffortFromAnswers(0.0, 0.9, 0.99, null, $low), 'high wins over a medium raise');
        self::assertSame('medium', AgentReasoningEffortDecision::resolveEffortFromAnswers(0.0, 0.0, 0.90, null, $low));
        self::assertSame('medium', AgentReasoningEffortDecision::resolveEffortFromAnswers(null, null, null, 0.95, $low), 'the weighing question alone raises to medium');
        self::assertSame('low', AgentReasoningEffortDecision::resolveEffortFromAnswers(null, null, null, null, $low), 'no answers = the default');
        self::assertSame('medium', AgentReasoningEffortDecision::resolveEffortFromAnswers(0.0, 0.0, 0.0, null, 'medium'), 'a medium default is never lowered');
    }

    public function testTheEffectiveEffortRaisesToThePinAndClampsToTheCeiling(): void
    {
        self::assertSame('low', AgentReasoningEffortLimits::resolveEffectiveEffort(null, null, 'high'));
        self::assertSame('medium', AgentReasoningEffortLimits::resolveEffectiveEffort('low', 'medium', 'high'), 'the pin is a floor');
        self::assertSame('high', AgentReasoningEffortLimits::resolveEffectiveEffort('high', 'medium', 'high'), 'a raise above the pin stands');
        self::assertSame('medium', AgentReasoningEffortLimits::resolveEffectiveEffort('high', null, 'medium'), 'the ceiling clamps');
        self::assertSame('medium', AgentReasoningEffortLimits::resolveEffectiveEffort('low', 'high', 'medium'), 'the ceiling clamps the pin too');
        self::assertSame('high', AgentReasoningEffortLimits::resolveEffectiveEffort('high', null, 'bogus'), 'an unknown ceiling clamps nothing');
    }

    /** the active effort = the highest declared minimum over the base, clamped; the source names it. */
    public function testTheFloorIsTheHighestDeclaredMinimumAndNamesItsSource(): void
    {
        self::assertSame(['low', 'default'], self::floorPair(null, 'low', null, null, null, 'high'));
        self::assertSame(['medium', 'configured'], self::floorPair('medium', 'low', null, null, null, 'high'));
        self::assertSame(['medium', 'purpose'], self::floorPair(null, 'low', null, 'medium', null, 'high'));
        self::assertSame(['high', 'planItem'], self::floorPair(null, 'low', null, 'medium', 'high', 'high'), 'the item minimum above the purpose floor wins');
        self::assertSame(['medium', 'purpose'], self::floorPair(null, 'low', null, 'medium', 'low', 'high'), 'an item minimum below the purpose floor changes nothing');
        self::assertSame(['high', 'pin'], self::floorPair(null, 'low', 'high', 'medium', null, 'high'), 'the pin above the purpose floor names the pin');
        self::assertSame(['medium', 'purpose'], self::floorPair(null, 'low', 'medium', 'medium', null, 'high'), 'on a tie the more specific declaration names the source');
        self::assertSame(['medium', 'planItem'], self::floorPair(null, 'low', null, 'medium', 'high', 'medium'), 'the ceiling clamps the floor');
        self::assertSame(['medium', 'configured'], self::floorPair('medium', 'low', 'low', 'low', null, 'high'), 'nothing below the base is a source');
        // a raise is judged above the floor: medium answers at a medium floor stay medium, a high answer raises
        self::assertSame('medium', AgentReasoningEffortDecision::resolveEffortFromAnswers(0.0, 0.0, 0.99, null, 'medium'));
        self::assertSame('high', AgentReasoningEffortDecision::resolveEffortFromAnswers(0.9, null, null, null, 'medium'));
    }

    public function testTheSliceRoundTripsAndAppliesOnlyForTheDecisionModelOrThePrecondition(): void
    {
        $decision = new AgentReasoningEffortDecision();
        $decision->ownerMessageIndex = 7;
        $decision->decidedBy = AgentReasoningEffortDecision::DECIDED_BY_DECISION_MODEL;
        $decision->preconditionReason = AgentReasoningEffortDecision::PRECONDITION_WORD_FLOOR;
        $decision->configuredEffort = 'medium';
        $decision->pinnedEffort = null;
        $decision->resolvedEffort = 'high';
        $decision->effectiveEffort = 'high';
        $decision->disputesWhatTheAssistantClaimsProbability = 0.91;
        self::assertTrue($decision->isApplied());
        $copy = new AgentReasoningEffortDecision();
        $copy->mapFromRepository($decision->mapToRepository());
        self::assertSame($decision->mapToRepository(), $copy->mapToRepository());

        $fallback = new AgentReasoningEffortDecision();
        $fallback->decidedBy = AgentReasoningEffortDecision::FALLBACK_TRANSPORT;
        $fallback->effectiveEffort = 'medium';
        self::assertFalse($fallback->isApplied(), 'a fallback stamp never applies — the model call keeps the catalog value');
        $shadow = new AgentReasoningEffortDecision();
        $shadow->decidedBy = AgentReasoningEffortDecision::DECIDED_BY_SHADOW;
        $shadow->effectiveEffort = 'low';
        self::assertFalse($shadow->isApplied());
        $precondition = new AgentReasoningEffortDecision();
        $precondition->decidedBy = AgentReasoningEffortDecision::DECIDED_BY_PRECONDITION;
        $precondition->effectiveEffort = 'low';
        self::assertTrue($precondition->isApplied(), 'the structural low applies without a call');
    }

    /** @return array{0: string, 1: string} {@see AgentReasoningEffortLimits::resolveFloorEffort()} as [effort, source] */
    protected static function floorPair(?string $configuredEffort, string $defaultEffort, ?string $pinnedEffort, ?string $purposeMinimum, ?string $planItemMinimum, string $ceilingEffort): array
    {
        $agentReasoningEffortFloor = AgentReasoningEffortLimits::resolveFloorEffort($configuredEffort, $defaultEffort, $pinnedEffort, $purposeMinimum, $planItemMinimum, $ceilingEffort);
        self::assertSame($ceilingEffort, $agentReasoningEffortFloor->ceilingEffort);
        return [$agentReasoningEffortFloor->effort, $agentReasoningEffortFloor->source];
    }
}
