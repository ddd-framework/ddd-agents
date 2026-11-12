<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentFinalTextAcceptance;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentFinalTextAcceptanceResult;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnClosingOutcome;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The pure checked-stop resolver, driven step by step the way the loop drives it (each guard
 * runs only when the resolver asks for it): `deterministicCounter` is today's sequence, `decisionModel` lets a verdict decide,
 * every UNAVAILABLE outcome falls back to the ladder, the kept-as-today rows run `deterministicCounter` under both modes, and a
 * decision-caused bounce caps the turn at one.
 */
class AgentFinalTextAcceptanceTest extends UnitTestCase
{
    /** @var array{deterministic: int, decide: int} */
    protected array $calls = ['deterministic' => 0, 'decide' => 0];

    protected function resolve(string $turnClosingMode, bool $ladderBounces, ?AgentTurnClosingOutcome $outcome, bool $counterFirstMandatory = false, bool $priorDecisionBounce = false, bool $askable = true): AgentFinalTextAcceptanceResult
    {
        $this->calls = ['deterministic' => 0, 'decide' => 0];
        $acceptance = AgentFinalTextAcceptance::start($turnClosingMode, $counterFirstMandatory, $priorDecisionBounce, $askable);
        while (!$acceptance->isResolved()) {
            if ($acceptance->needsCounterVerdict()) {
                $this->calls['deterministic']++;
                $acceptance->applyCounterVerdict($ladderBounces);
                continue;
            }
            self::assertTrue($acceptance->needsDecisionOutcome());
            $this->calls['decide']++;
            $acceptance->applyDecisionOutcome($outcome ?? AgentTurnClosingOutcome::unavailable(AgentTurnClosingOutcome::REASON_ERROR));
        }
        return $acceptance->result;
    }

    public function testBesideIsTodaysSequenceLadderFirstThenTheDecisionModelWhereSilent(): void
    {
        $beside = AgentDecisionModelSettings::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER;
        $result = $this->resolve($beside, true, AgentTurnClosingOutcome::verdict(false));
        self::assertTrue($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::TRIGGER_DETERMINISTIC_COUNTER, $result->trigger);
        self::assertSame(['deterministic' => 1, 'decide' => 0], $this->calls, 'the ladder fired — the decision model is never asked');
        self::assertSame(AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE, $result->signalSource());

        $result = $this->resolve($beside, false, AgentTurnClosingOutcome::verdict(true));
        self::assertTrue($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::TRIGGER_DECISION_MODEL, $result->trigger);
        self::assertSame(['deterministic' => 1, 'decide' => 1], $this->calls);
        self::assertSame(AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE, $result->signalSource(), 'a decision-model bounce under beside keeps today\'s tag');

        foreach ([AgentTurnClosingOutcome::verdict(false), AgentTurnClosingOutcome::unavailable(AgentTurnClosingOutcome::REASON_TRANSPORT)] as $quiet) {
            $result = $this->resolve($beside, false, $quiet);
            self::assertFalse($result->bounce);
            self::assertNull($result->fallbackReason, 'under beside there is no fallback — the ladder already ran');
            self::assertSame(['deterministic' => 1, 'decide' => 1], $this->calls);
        }

        $result = $this->resolve($beside, false, AgentTurnClosingOutcome::verdict(true), askable: false);
        self::assertFalse($result->bounce);
        self::assertSame(['deterministic' => 1, 'decide' => 0], $this->calls, 'a stall / widget turn never reaches the decision model');
    }

    public function testReplaceLetsTheVerdictDecideAndNeverConsultsTheLadderOnAVerdict(): void
    {
        $replace = AgentDecisionModelSettings::TURN_CLOSING_MODE_DECISION_MODEL;
        $result = $this->resolve($replace, true, AgentTurnClosingOutcome::verdict(false));
        self::assertFalse($result->bounce, 'the decision model said "asks the next question" — the ladder that would have bounced is NOT consulted');
        self::assertSame(['deterministic' => 0, 'decide' => 1], $this->calls);
        self::assertSame(AgentFinalTextAcceptanceResult::RULE_DECISION_MODEL, $result->decidingRule);

        $result = $this->resolve($replace, false, AgentTurnClosingOutcome::verdict(true));
        self::assertTrue($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::TRIGGER_DECISION_MODEL, $result->trigger);
        self::assertSame(AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE_DECISION, $result->signalSource(), 'the second tag — the cap-1 rule counts exactly these');
        self::assertSame(['deterministic' => 0, 'decide' => 1], $this->calls);
    }

    public function testEveryUnavailableOutcomeFallsBackToTheLadderByteIdenticalToBeside(): void
    {
        $replace = AgentDecisionModelSettings::TURN_CLOSING_MODE_DECISION_MODEL;
        $reasons = [
            AgentTurnClosingOutcome::REASON_SITE_OFF, AgentTurnClosingOutcome::REASON_NOT_ASKED, AgentTurnClosingOutcome::REASON_BREAKER,
            AgentTurnClosingOutcome::REASON_TRANSPORT, AgentTurnClosingOutcome::REASON_UNPARSABLE, AgentTurnClosingOutcome::REASON_ERROR,
            AgentTurnClosingOutcome::REASON_REFUSED, AgentTurnClosingOutcome::REASON_SHADOW,
        ];
        foreach ($reasons as $reason) {
            foreach ([true, false] as $ladderBounces) {
                $result = $this->resolve($replace, $ladderBounces, AgentTurnClosingOutcome::unavailable($reason));
                self::assertSame($ladderBounces, $result->bounce, "$reason: the ladder's verdict is returned");
                self::assertSame($ladderBounces ? AgentFinalTextAcceptanceResult::TRIGGER_DETERMINISTIC_COUNTER : null, $result->trigger);
                self::assertSame($reason, $result->fallbackReason);
                self::assertSame(AgentFinalTextAcceptanceResult::RULE_COUNTER_FALLBACK_PREFIX . $reason, $result->decidingRule);
                self::assertSame(['deterministic' => 1, 'decide' => 1], $this->calls, "$reason: exactly one ladder run");
                self::assertSame(AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE, $result->signalSource(), 'a fallback bounce keeps today\'s tag');
            }
        }
    }

    public function testTheKeptAsTodayRowsRunTheBesideSequenceUnderReplaceWithoutTheCapOneShortcut(): void
    {
        $replace = AgentDecisionModelSettings::TURN_CLOSING_MODE_DECISION_MODEL;
        // the ladder fires (e.g. the announce-loop of a widget final stretch) — the decision model never called
        $result = $this->resolve($replace, true, AgentTurnClosingOutcome::verdict(false), counterFirstMandatory: true, priorDecisionBounce: true);
        self::assertTrue($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::TRIGGER_DETERMINISTIC_COUNTER, $result->trigger);
        self::assertSame(AgentFinalTextAcceptanceResult::RULE_COUNTER_FIRST_MANDATORY, $result->decidingRule);
        self::assertSame(['deterministic' => 1, 'decide' => 0], $this->calls);
        // the ladder is silent on the FIRST announce turn of a stale final stretch — the decision model is asked as today,
        // its "announces" verdict bounces with today's tag and today's cap; a prior decision bounce does not shortcut it
        $result = $this->resolve($replace, false, AgentTurnClosingOutcome::verdict(true), counterFirstMandatory: true, priorDecisionBounce: true);
        self::assertTrue($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::TRIGGER_DECISION_MODEL, $result->trigger);
        self::assertSame(AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE, $result->signalSource());
        self::assertSame(['deterministic' => 1, 'decide' => 1], $this->calls);
        // a "does not announce" verdict on such a row does NOT accept the text against a silent ladder — same as beside
        $result = $this->resolve($replace, false, AgentTurnClosingOutcome::verdict(false), counterFirstMandatory: true);
        self::assertFalse($result->bounce);
        self::assertSame(['deterministic' => 1, 'decide' => 1], $this->calls);
    }

    public function testADecisionCausedBounceCapsTheTurnAtOneUnderReplace(): void
    {
        $replace = AgentDecisionModelSettings::TURN_CLOSING_MODE_DECISION_MODEL;
        $result = $this->resolve($replace, true, AgentTurnClosingOutcome::verdict(true), priorDecisionBounce: true);
        self::assertFalse($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::RULE_ONE_BOUNCE_PER_TURN_REACHED, $result->decidingRule);
        self::assertSame(['deterministic' => 0, 'decide' => 0], $this->calls, 'neither guard runs again this turn');
        // under beside a prior decision bounce means nothing — the cap of 3 governs there
        $result = $this->resolve(AgentDecisionModelSettings::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER, true, null, priorDecisionBounce: true);
        self::assertTrue($result->bounce);
    }

    public function testANonAskableTextUnderReplaceRunsTheLadderOnly(): void
    {
        $result = $this->resolve(AgentDecisionModelSettings::TURN_CLOSING_MODE_DECISION_MODEL, true, AgentTurnClosingOutcome::verdict(false), askable: false);
        self::assertTrue($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::RULE_TEXT_NOT_JUDGEABLE, $result->decidingRule);
        self::assertSame(['deterministic' => 1, 'decide' => 0], $this->calls);
    }

    public function testCounterThenDecisionModelAsksTheDecisionModelOnlyWhereTheCounterFiresAndLetsItVetoTheBounce(): void
    {
        $counterFirst = AgentDecisionModelSettings::TURN_CLOSING_MODE_COUNTER_THEN_DECISION_MODEL;
        // the counter is silent → accepted, the decision model never asked
        $result = $this->resolve($counterFirst, false, AgentTurnClosingOutcome::verdict(true));
        self::assertFalse($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::RULE_COUNTER_SILENT, $result->decidingRule);
        self::assertSame(['deterministic' => 1, 'decide' => 0], $this->calls, 'where the counter is silent the decision model is not asked');
        // the counter fires, the decision model says "does not announce" → the bounce is vetoed
        $result = $this->resolve($counterFirst, true, AgentTurnClosingOutcome::verdict(false));
        self::assertFalse($result->bounce);
        self::assertNull($result->trigger);
        self::assertSame(AgentFinalTextAcceptanceResult::RULE_DECISION_MODEL_VETOED_COUNTER, $result->decidingRule);
        self::assertSame(['deterministic' => 1, 'decide' => 1], $this->calls);
        // the counter fires, the decision model agrees → bounced with TODAY's tag (the counter's cap applies)
        $result = $this->resolve($counterFirst, true, AgentTurnClosingOutcome::verdict(true));
        self::assertTrue($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::TRIGGER_DETERMINISTIC_COUNTER, $result->trigger);
        self::assertSame(AgentFinalTextAcceptanceResult::RULE_DECISION_MODEL_CONFIRMED_COUNTER, $result->decidingRule);
        self::assertSame(AIConversationMessage::SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE, $result->signalSource());
        // the counter fires, the decision model unavailable (every reason, shadow included) → the counter's bounce stands
        foreach ([AgentTurnClosingOutcome::REASON_TRANSPORT, AgentTurnClosingOutcome::REASON_SHADOW, AgentTurnClosingOutcome::REASON_NOT_ASKED, AgentTurnClosingOutcome::REASON_BREAKER] as $reason) {
            $result = $this->resolve($counterFirst, true, AgentTurnClosingOutcome::unavailable($reason));
            self::assertTrue($result->bounce, $reason);
            self::assertSame(AgentFinalTextAcceptanceResult::TRIGGER_DETERMINISTIC_COUNTER, $result->trigger);
            self::assertSame($reason, $result->fallbackReason);
            self::assertSame(['deterministic' => 1, 'decide' => 1], $this->calls, $reason);
        }
        // a stall / widget text the counter wants to bounce is not judgeable → the counter's bounce, no call
        $result = $this->resolve($counterFirst, true, AgentTurnClosingOutcome::verdict(false), askable: false);
        self::assertTrue($result->bounce);
        self::assertSame(['deterministic' => 1, 'decide' => 0], $this->calls);
        // the kept-as-today rows run today's sequence
        $result = $this->resolve($counterFirst, true, AgentTurnClosingOutcome::verdict(false), counterFirstMandatory: true);
        self::assertTrue($result->bounce);
        self::assertSame(AgentFinalTextAcceptanceResult::RULE_COUNTER_FIRST_MANDATORY, $result->decidingRule);
        self::assertSame(['deterministic' => 1, 'decide' => 0], $this->calls);
    }
}
