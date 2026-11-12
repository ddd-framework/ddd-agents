<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The ONE resolver of the checked stop, pure: which guard judges a work-less final text under
 * which turn-closing mode. It asks for the two guards one at a time ({@see needsCounterVerdict()},
 * {@see needsDecisionOutcome()}) so a branch that does not need one never pays for it, and the caller runs each guard
 * exactly when it is asked for — the counter's ladder ({@see applyCounterVerdict()}) and the decision call
 * ({@see applyDecisionOutcome()}) — until the acceptance {@see isResolved()}.
 *
 * - `deterministicCounter` = today's sequence, literally: the deterministic ladder first, the decision model only where it stayed silent and the
 *   pre-conditions hold (`decisionModelMayJudge`), a decision-model bounce keeps today's tag and today's cap.
 * - `decisionModel` = the decision model judges every askable text; a verdict is final (bounce on "announces", accept otherwise — the
 *   ladder is NOT consulted); an UNAVAILABLE outcome falls back to the ladder, byte-identical to `deterministicCounter`; after a
 *   decision-model-caused bounce in this turn every later text of the turn is accepted (cap 1).
 * - `counterThenDecisionModel` = the counter PROPOSES and the decision model CONFIRMS: the decision model is asked only on a text the
 *   counter wants to bounce; a verdict "does not announce" vetoes the bounce (the text is accepted); a verdict
 *   "announces" or any UNAVAILABLE outcome keeps the counter's bounce, with today's tag. Where the counter is silent
 *   the decision model is never asked.
 * - The kept-as-today rows (an autonomous purpose, the widget final stretch, the final act) run the `deterministicCounter`
 *   sequence under ALL modes, without the cap-1 shortcut — neither `decisionModel` nor `counterThenDecisionModel` touches them.
 *
 * @phpstan-consistent-constructor
 */
class AgentFinalTextAcceptance extends ValueObject
{
    /** The counter (`counterThenDecisionModel`, not mandatory) proposes, the decision model confirms or vetoes. */
    public const string SEQUENCE_COUNTER_PROPOSES = 'counterProposes';

    /** The counter first, the decision model only where it stayed silent (`deterministicCounter`, or a mandatory counter-first row). */
    public const string SEQUENCE_COUNTER_FIRST = 'counterFirst';

    /** The decision model decides, the counter only where the decision model cannot answer or may not judge (`decisionModel`, not mandatory). */
    public const string SEQUENCE_DECISION_MODEL_FIRST = 'decisionModelFirst';

    public const string NEXT_GUARD_COUNTER = 'counter';
    public const string NEXT_GUARD_DECISION = 'decision';

    /** What the acceptance has resolved so far — final once {@see isResolved()}. */
    public AgentFinalTextAcceptanceResult $result;

    /** Which guard the caller runs next ({@see NEXT_GUARD_COUNTER} / {@see NEXT_GUARD_DECISION}), null = resolved. */
    public ?string $nextGuard = null;

    /** One of the SEQUENCE_* constants. */
    public string $sequence = self::SEQUENCE_COUNTER_FIRST;

    /** The text may be judged by the decision model (the pre-conditions of the decision call hold). */
    public bool $decisionModelMayJudge = false;

    /** The first step: the sequence of the mode and the guard it asks for first (none when the cap-1 rule accepts). */
    public static function start(
        string $turnClosingMode,
        bool $counterFirstMandatory,
        bool $priorDecisionBounceInTurn,
        bool $decisionModelMayJudge
    ): static {
        $acceptance = new static();
        $acceptance->decisionModelMayJudge = $decisionModelMayJudge;
        $acceptance->result = new AgentFinalTextAcceptanceResult();
        $acceptance->result->turnClosingMode = $turnClosingMode;
        if ($turnClosingMode === AgentDecisionModelSettings::TURN_CLOSING_MODE_COUNTER_THEN_DECISION_MODEL && !$counterFirstMandatory) {
            $acceptance->sequence = self::SEQUENCE_COUNTER_PROPOSES;
            $acceptance->nextGuard = self::NEXT_GUARD_COUNTER;
            return $acceptance;
        }
        $replaces = $turnClosingMode === AgentDecisionModelSettings::TURN_CLOSING_MODE_DECISION_MODEL && !$counterFirstMandatory;
        if (!$replaces) {
            $acceptance->sequence = self::SEQUENCE_COUNTER_FIRST;
            $acceptance->result->decidingRule = $counterFirstMandatory && $turnClosingMode !== AgentDecisionModelSettings::TURN_CLOSING_MODE_DETERMINISTIC_COUNTER
                ? AgentFinalTextAcceptanceResult::RULE_COUNTER_FIRST_MANDATORY
                : AgentFinalTextAcceptanceResult::RULE_DETERMINISTIC_COUNTER_FIRST;
            $acceptance->nextGuard = self::NEXT_GUARD_COUNTER;
            return $acceptance;
        }
        $acceptance->sequence = self::SEQUENCE_DECISION_MODEL_FIRST;
        if ($priorDecisionBounceInTurn) {
            $acceptance->result->decidingRule = AgentFinalTextAcceptanceResult::RULE_ONE_BOUNCE_PER_TURN_REACHED;
            return $acceptance; // the decision model judged this turn's text once — accept every later text of the turn
        }
        if (!$decisionModelMayJudge) {
            $acceptance->result->decidingRule = AgentFinalTextAcceptanceResult::RULE_TEXT_NOT_JUDGEABLE;
            $acceptance->nextGuard = self::NEXT_GUARD_COUNTER;
            return $acceptance;
        }
        $acceptance->nextGuard = self::NEXT_GUARD_DECISION;
        return $acceptance;
    }

    public function isResolved(): bool
    {
        return $this->nextGuard === null;
    }

    public function needsCounterVerdict(): bool
    {
        return $this->nextGuard === self::NEXT_GUARD_COUNTER;
    }

    public function needsDecisionOutcome(): bool
    {
        return $this->nextGuard === self::NEXT_GUARD_DECISION;
    }

    /** The counter's verdict (today's whole bounce expression: pre-conditions + ladder) — asked for by {@see needsCounterVerdict()}. */
    public function applyCounterVerdict(bool $counterBounces): void
    {
        $this->nextGuard = null;
        if ($this->sequence === self::SEQUENCE_COUNTER_PROPOSES) {
            if (!$counterBounces) {
                $this->result->decidingRule = AgentFinalTextAcceptanceResult::RULE_COUNTER_SILENT;
                return; // the counter accepts — the decision model is not asked
            }
            $this->result->bounce = true;
            $this->result->trigger = AgentFinalTextAcceptanceResult::TRIGGER_DETERMINISTIC_COUNTER;
            if (!$this->decisionModelMayJudge) {
                $this->result->decidingRule = AgentFinalTextAcceptanceResult::RULE_TEXT_NOT_JUDGEABLE;
                return;
            }
            $this->nextGuard = self::NEXT_GUARD_DECISION;
            return;
        }
        if ($counterBounces) {
            $this->result->bounce = true;
            $this->result->trigger = AgentFinalTextAcceptanceResult::TRIGGER_DETERMINISTIC_COUNTER;
            return;
        }
        if ($this->sequence === self::SEQUENCE_COUNTER_FIRST && $this->decisionModelMayJudge) {
            $this->nextGuard = self::NEXT_GUARD_DECISION;
        }
    }

    /** The decision call's outcome (its own gate inside) — asked for by {@see needsDecisionOutcome()}. */
    public function applyDecisionOutcome(AgentTurnClosingOutcome $outcome): void
    {
        $this->nextGuard = null;
        $this->result->outcome = $outcome;
        if ($this->sequence === self::SEQUENCE_COUNTER_PROPOSES) {
            if ($outcome->isVerdict()) {
                if (!$outcome->wouldBounce) {
                    $this->result->bounce = false;
                    $this->result->trigger = null;
                    $this->result->decidingRule = AgentFinalTextAcceptanceResult::RULE_DECISION_MODEL_VETOED_COUNTER;
                    return;
                }
                $this->result->decidingRule = AgentFinalTextAcceptanceResult::RULE_DECISION_MODEL_CONFIRMED_COUNTER;
                return;
            }
            $this->result->fallbackReason = $outcome->reason;
            $this->result->decidingRule = AgentFinalTextAcceptanceResult::RULE_COUNTER_FALLBACK_PREFIX . ($outcome->reason ?? '?');
            return; // the decision model unavailable — the counter's bounce stands
        }
        if ($this->sequence === self::SEQUENCE_COUNTER_FIRST) {
            if ($outcome->isVerdict() && $outcome->wouldBounce) {
                $this->result->bounce = true;
                $this->result->trigger = AgentFinalTextAcceptanceResult::TRIGGER_DECISION_MODEL;
            }
            return;
        }
        if ($outcome->isVerdict()) {
            $this->result->decidingRule = AgentFinalTextAcceptanceResult::RULE_DECISION_MODEL;
            $this->result->bounce = $outcome->wouldBounce;
            $this->result->trigger = $outcome->wouldBounce ? AgentFinalTextAcceptanceResult::TRIGGER_DECISION_MODEL : null;
            return;
        }
        $this->result->fallbackReason = $outcome->reason;
        $this->result->decidingRule = AgentFinalTextAcceptanceResult::RULE_COUNTER_FALLBACK_PREFIX . ($outcome->reason ?? '?');
        $this->nextGuard = self::NEXT_GUARD_COUNTER; // the decision model unavailable — the ladder decides
    }
}
