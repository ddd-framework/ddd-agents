<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentStallRecoveryDecision;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The pure stall-recovery decision: the offered moves, the move to apply, the slice round trip. */
class AgentStallRecoveryDecisionTest extends UnitTestCase
{
    public function testAnInapplicableMoveIsRemovedBeforeTheCallAndNoneAlwaysStays(): void
    {
        self::assertSame(
            [AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL, AgentStallRecoveryDecision::MOVE_LOAD_REQUIRED_CAPABILITY, AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT, AgentStallRecoveryDecision::MOVE_NONE],
            AgentStallRecoveryDecision::resolveOfferedMoves(true, true)
        );
        self::assertSame(
            [AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT, AgentStallRecoveryDecision::MOVE_NONE],
            AgentStallRecoveryDecision::resolveOfferedMoves(false, false),
            'no work tools, nothing loadable → only the text move (and none)'
        );
        self::assertSame(
            [AgentStallRecoveryDecision::MOVE_LOAD_REQUIRED_CAPABILITY, AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT, AgentStallRecoveryDecision::MOVE_NONE],
            AgentStallRecoveryDecision::resolveOfferedMoves(false, true)
        );
    }

    public function testTheRulePerConsequenceTextMovesNeedTheTopChoiceAForcedCallNeedsProbabilityAndMargin(): void
    {
        $text = AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT;
        $load = AgentStallRecoveryDecision::MOVE_LOAD_REQUIRED_CAPABILITY;
        $call = AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL;
        $none = AgentStallRecoveryDecision::MOVE_NONE;
        $offered = AgentStallRecoveryDecision::resolveOfferedMoves(true, true);
        // a live case: the right text move at 0.24 confidence, the unique top choice → applied
        self::assertSame($text, AgentStallRecoveryDecision::resolveMoveToApply($text, 0.24, $offered, [$call => 0.2, $load => 0.2, $text => 0.4, $none => 0.2]));
        self::assertSame($load, AgentStallRecoveryDecision::resolveMoveToApply($load, 0.3, $offered, [$call => 0.1, $load => 0.5, $text => 0.3, $none => 0.1]));
        self::assertNull(AgentStallRecoveryDecision::resolveMoveToApply($text, 0.5, $offered, [$call => 0.4, $load => 0.0, $text => 0.4, $none => 0.2]), 'a tie is not a unique top choice');
        self::assertNull(AgentStallRecoveryDecision::resolveMoveToApply($text, 0.9, $offered, [$call => 0.6, $load => 0.0, $text => 0.3, $none => 0.1]), 'the pick disagrees with its own distribution');
        // the forced call: probability ≥ 0.8 AND margin ≥ 0.3
        self::assertSame($call, AgentStallRecoveryDecision::resolveMoveToApply($call, 0.9, $offered, [$call => 0.85, $load => 0.05, $text => 0.05, $none => 0.05]));
        self::assertNull(AgentStallRecoveryDecision::resolveMoveToApply($call, 0.9, $offered, [$call => 0.6, $load => 0.3, $text => 0.05, $none => 0.05]), 'below the probability floor');
        self::assertNull(AgentStallRecoveryDecision::resolveMoveToApply($call, 0.9, $offered, [$call => 0.55, $load => 0.45, $text => 0.0, $none => 0.0]), 'a contested winner is not forced');
        // no usable distribution → the legacy confidence floor
        self::assertSame($call, AgentStallRecoveryDecision::resolveMoveToApply($call, 0.8, $offered));
        self::assertNull(AgentStallRecoveryDecision::resolveMoveToApply($call, 0.69, $offered), 'below the floor = today\'s ladder');
        self::assertNull(AgentStallRecoveryDecision::resolveMoveToApply($text, 0.5, $offered, [$call => 0.5, 'bogus' => 0.5]), 'an outside key invalidates the distribution and the confidence decides');
        self::assertNull(AgentStallRecoveryDecision::resolveMoveToApply($none, 0.99, $offered, [$none => 1.0]));
        self::assertNull(AgentStallRecoveryDecision::resolveMoveToApply($load, 0.99, AgentStallRecoveryDecision::resolveOfferedMoves(true, false)), 'a move that was not offered never applies');
        self::assertNull(AgentStallRecoveryDecision::resolveMoveToApply(null, 0.99, $offered));
    }

    public function testTheSliceRoundTrips(): void
    {
        $decision = new AgentStallRecoveryDecision();
        $decision->ownerMessageIndex = 3;
        $decision->decidedBy = AgentStallRecoveryDecision::DECIDED_BY_DECISION_MODEL;
        $decision->stallShape = AgentStallRecoveryDecision::SHAPE_PRE_CALL_ANNOUNCE;
        $decision->stallCount = 2;
        $decision->offeredMoves = AgentStallRecoveryDecision::resolveOfferedMoves(true, true);
        $decision->move = AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT;
        $decision->moveConfidence = 0.83;
        $decision->probabilities = ['end_the_turn_with_text' => 0.83, 'none' => 0.1];
        $decision->applied = true;
        $copy = new AgentStallRecoveryDecision();
        $copy->mapFromRepository($decision->mapToRepository());
        self::assertSame($decision->mapToRepository(), $copy->mapToRepository());
        self::assertTrue($copy->isApplied());
    }
}
