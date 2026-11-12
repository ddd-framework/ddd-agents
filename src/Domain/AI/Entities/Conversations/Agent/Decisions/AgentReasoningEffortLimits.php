<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Everything that decides which reasoning effort a conversation's model call is sent with, read ONCE per call: the model catalog's configured effort and ceiling, the purpose's base effort and minimum, the next
 * open plan item's minimum, the row pin, a fixed effort, the reasoning-effort site's mode and whether the conversation
 * works without an owner turn (a delegation or an autonomous purpose). Pure: the world reads are the gatherer's
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentReasoningEffortDecisionsService::buildReasoningEffortLimits()}).
 *
 * The four answers to "which effort" read it:
 * - {@see resolveEffortToSend()} — the effort the model call is sent with;
 * - {@see buildFloor()} — the active effort the decision model is told;
 * - {@see resolveFallbackEffort()} / {@see resolveAnsweredEffort()} — the effective effort the site stamps;
 * - {@see buildBaseline()} — the admin panel's baseline.
 *
 * One rule: the baseline IS the effort sent without a turn stamp and without the next plan item's
 * minimum (both are turn-specific), and the purpose's base effort is sent on every path a turn effort is resolved on.
 *
 * @phpstan-consistent-constructor
 */
class AgentReasoningEffortLimits extends ValueObject
{
    /** @var string|null The model catalog's configured agentic effort (`agenticUseCase.reasoningEffort`), null = none. */
    public ?string $configuredEffort = null;

    /** @var string The ceiling every effort is clamped to (`agenticUseCase.maxReasoningEffort`, else `high`). */
    public string $ceilingEffort = AgentReasoningEffortDecision::DEFAULT_CEILING;

    /** @var string|null The purpose's declared base effort (`reasoningEffort` in its frontmatter), null = none. */
    public ?string $purposeBaseEffort = null;

    /** @var string|null The purpose's declared minimum (`minReasoningEffort` in its frontmatter), null = none. */
    public ?string $purposeMinimum = null;

    /** @var string|null The minimum the next open plan item declares, null = none. */
    public ?string $planItemMinimum = null;

    /** @var string|null The conversation's row pin (canonical), null = none. */
    public ?string $pinnedEffort = null;

    /** @var string|null The conversation's fixed effort (a measurement setting, canonical), null = none. */
    public ?string $fixedEffort = null;

    /** @var string The reasoning-effort site's mode for this conversation (`off` / `shadow` / `on`). */
    public string $siteMode = AgentDecisionModelSettings::MODE_OFF;

    /** @var bool A delegation or an autonomous purpose run: no decision-model verdict and no row pin. */
    public bool $worksWithoutAnOwnerTurn = false;

    /**
     * The limits the conversation itself carries — the site mode, the row pin and the fixed effort of its run
     * settings, the next open plan item's minimum. The catalog, the purpose and the autonomy are the gatherer's reads;
     * without them the catalog effort is unknown and the ceiling is the default.
     */
    public static function fromConversation(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): static
    {
        $agentReasoningEffortLimits = new static();
        $agentReasoningEffortLimits->siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_REASONING_EFFORT, $conversation);
        $agentReasoningEffortLimits->pinnedEffort = $decisionModelSettings->reasoningEffortPinForConversation($conversation);
        $agentReasoningEffortLimits->fixedEffort = self::readFixedEffort($conversation);
        $agentReasoningEffortLimits->planItemMinimum = $conversation->getTaskPlan()?->items?->getMinReasoningEffortOfFirstOpenItem();
        return $agentReasoningEffortLimits;
    }

    /** The conversation's FIXED effort ({@see \DDD\Domain\AI\Entities\Conversations\Messages\RunSettings::$fixedReasoningEffort}), canonical; null = none. */
    public static function readFixedEffort(AIConversation $conversation): ?string
    {
        return AgentDecisionModelSettings::canonicalReasoningEffort(
            isset($conversation->runSettings) ? ($conversation->runSettings->fixedReasoningEffort ?? null) : null
        );
    }

    /** The effort a call is sent with when nothing raises it: the purpose's base effort, else the catalog's, else `low`. */
    public function getBaseEffort(): string
    {
        return $this->purposeBaseEffort ?? $this->configuredEffort ?? AgentDecisionModelSettings::REASONING_EFFORT_LOW;
    }

    /**
     * The effort THIS model call is sent with. A fixed effort is sent as it is, clamped to the ceiling.
     * Site `off` sends nothing (null = the catalog value). Otherwise the turn's effort
     * ({@see resolveTurnEffortBeforeDeclaredMinimums()}), raised by the purpose's and the plan item's minimums.
     *
     * @param AgentReasoningEffortDecision|null $reasoningEffortDecision the stamp on the working state
     * @param int|null $currentInitiatorMessageIndex the newest turn initiator's index — the stamp applies only to it
     * @param bool $readsThePlanItemMinimum false = without the next plan item's minimum (the baseline)
     */
    public function resolveEffortToSend(?AgentReasoningEffortDecision $reasoningEffortDecision, ?int $currentInitiatorMessageIndex, bool $readsThePlanItemMinimum = true): ?string
    {
        if ($this->fixedEffort !== null) {
            return self::clampTo($this->fixedEffort, $this->ceilingEffort);
        }
        if ($this->siteMode === AgentDecisionModelSettings::MODE_OFF) {
            return null;
        }
        return $this->resolveEffortRaisedByDeclaredMinimums($reasoningEffortDecision, $currentInitiatorMessageIndex, $readsThePlanItemMinimum)
            ?? $this->resolveTurnEffortBeforeDeclaredMinimums($reasoningEffortDecision, $currentInitiatorMessageIndex);
    }

    /**
     * The turn's effort before the declared minimums. For an owner turn: the stamp's effective effort while it is
     * applied and belongs to the current initiator; under `on` the higher of the base effort and the pin, clamped.
     * Under `shadow` and without an owner turn (a delegation, an autonomous run — no verdict, no pin) there is no site
     * default: the declarations only — the purpose's base effort, raised to the row pin for an owner turn — clamped;
     * null when none is declared (the catalog value).
     */
    public function resolveTurnEffortBeforeDeclaredMinimums(?AgentReasoningEffortDecision $reasoningEffortDecision, ?int $currentInitiatorMessageIndex): ?string
    {
        if (!$this->worksWithoutAnOwnerTurn) {
            if ($reasoningEffortDecision !== null
                && $reasoningEffortDecision->isApplied()
                && $currentInitiatorMessageIndex !== null
                && $reasoningEffortDecision->ownerMessageIndex === $currentInitiatorMessageIndex
            ) {
                return $reasoningEffortDecision->effectiveEffort;
            }
            if ($this->siteMode === AgentDecisionModelSettings::MODE_ON) {
                return self::resolveEffectiveEffort(null, $this->pinnedEffort, $this->ceilingEffort, $this->getBaseEffort());
            }
        }
        $declaredEffort = $this->purposeBaseEffort;
        if (!$this->worksWithoutAnOwnerTurn && $this->pinnedEffort !== null) {
            $declaredEffort = $declaredEffort !== null ? self::raiseTo($declaredEffort, $this->pinnedEffort) : $this->pinnedEffort;
        }
        return $declaredEffort !== null ? self::clampTo($declaredEffort, $this->ceilingEffort) : null;
    }

    /**
     * The effort the declared minimums (the purpose's, the next plan item's) raise the turn's effort to, clamped to
     * the ceiling — read against what is actually sent (the base effort when the turn's effort is null). Null when no
     * minimum raises it, when a fixed effort is sent or when the site is `off`.
     */
    public function resolveEffortRaisedByDeclaredMinimums(?AgentReasoningEffortDecision $reasoningEffortDecision, ?int $currentInitiatorMessageIndex, bool $readsThePlanItemMinimum = true): ?string
    {
        $planItemMinimum = $readsThePlanItemMinimum ? $this->planItemMinimum : null;
        if ($this->fixedEffort !== null || $this->siteMode === AgentDecisionModelSettings::MODE_OFF
            || ($this->purposeMinimum === null && $planItemMinimum === null)
        ) {
            return null;
        }
        $effortBeforeMinimums = $this->resolveEffortBeforeDeclaredMinimums($reasoningEffortDecision, $currentInitiatorMessageIndex);
        $raisedEffort = $effortBeforeMinimums;
        foreach ([$this->purposeMinimum, $planItemMinimum] as $minimum) {
            if ($minimum !== null) {
                $raisedEffort = self::raiseTo($raisedEffort, $minimum);
            }
        }
        $raisedEffort = self::clampTo($raisedEffort, $this->ceilingEffort);
        return $raisedEffort !== $effortBeforeMinimums ? $raisedEffort : null;
    }

    /** The effort the declared minimums are read against: the turn's effort, else the base effort. */
    public function resolveEffortBeforeDeclaredMinimums(?AgentReasoningEffortDecision $reasoningEffortDecision, ?int $currentInitiatorMessageIndex): string
    {
        return $this->resolveTurnEffortBeforeDeclaredMinimums($reasoningEffortDecision, $currentInitiatorMessageIndex) ?? $this->getBaseEffort();
    }

    /**
     * The turn's ACTIVE effort before any decision and its source — what the decision model is told the assistant
     * already reasons at ({@see resolveFloorEffort()} over the base effort, the pin, the purpose minimum and the plan
     * item's minimum, clamped to the ceiling).
     *
     */
    public function buildFloor(): AgentReasoningEffortFloor
    {
        return self::resolveFloorEffort(
            $this->purposeBaseEffort ?? $this->configuredEffort,
            AgentDecisionModelSettings::REASONING_EFFORT_LOW,
            $this->pinnedEffort,
            $this->purposeMinimum,
            $this->planItemMinimum,
            $this->ceilingEffort
        );
    }

    /**
     * The effective effort a FALLBACK stamps: the catalog effort (else the base effort) raised to the turn's floor,
     * clamped — an outage never sends less than the active effort.
     */
    public function resolveFallbackEffort(string $floorEffort): string
    {
        return self::clampTo(self::raiseTo($this->configuredEffort ?? $this->getBaseEffort(), $floorEffort), $this->ceilingEffort);
    }

    /** The effective effort an ANSWER stamps: the resolved effort (else the floor) raised to the pin, clamped. */
    public function resolveAnsweredEffort(?string $resolvedEffort, string $floorEffort): string
    {
        return self::resolveEffectiveEffort($resolvedEffort, $this->pinnedEffort, $this->ceilingEffort, $floorEffort);
    }

    /**
     * The admin panel's baseline — the effort a turn is sent with when nothing turn-specific raises it: exactly
     * {@see resolveEffortToSend()} without a turn stamp and without the next plan item's minimum; when nothing is sent,
     * the catalog effort the model call falls back to (null = none configured, the provider's default).
     */
    public function buildBaseline(): AgentReasoningEffortBaseline
    {
        $sentEffort = $this->resolveEffortToSend(null, null, false);
        return AgentReasoningEffortBaseline::create($sentEffort ?? $this->configuredEffort, $this->resolveBaselineSource($sentEffort));
    }

    /**
     * What set the baseline: a fixed effort; the catalog when the site is `off`; the purpose's minimum when it raises
     * the turn's effort or ties it; the catalog when nothing is sent; else the turn's effort's own declaration — the row pin when
     * it is at least the effort, the purpose's base or the catalog when they lift it above the site default under `on`
     * (or declare it under `shadow` / without an owner turn), else the site default.
     */
    protected function resolveBaselineSource(?string $sentEffort): string
    {
        if ($this->fixedEffort !== null) {
            return AgentReasoningEffortDecision::FLOOR_SOURCE_FIXED;
        }
        if ($this->siteMode === AgentDecisionModelSettings::MODE_OFF) {
            return AgentReasoningEffortDecision::FLOOR_SOURCE_CONFIGURED;
        }
        if ($this->purposeMinimum !== null && (
            $this->resolveEffortRaisedByDeclaredMinimums(null, null, false) !== null
            || AgentDecisionModelSettings::reasoningEffortRank($this->purposeMinimum) === AgentDecisionModelSettings::reasoningEffortRank($this->resolveEffortBeforeDeclaredMinimums(null, null))
        )) {
            return AgentReasoningEffortDecision::FLOOR_SOURCE_PURPOSE;
        }
        $turnEffort = $this->resolveTurnEffortBeforeDeclaredMinimums(null, null);
        if ($turnEffort === null) {
            return AgentReasoningEffortDecision::FLOOR_SOURCE_CONFIGURED;
        }
        if (!$this->worksWithoutAnOwnerTurn && $this->pinnedEffort !== null
            && AgentDecisionModelSettings::reasoningEffortRank($this->pinnedEffort) >= AgentDecisionModelSettings::reasoningEffortRank($turnEffort)
        ) {
            return AgentReasoningEffortDecision::FLOOR_SOURCE_PIN;
        }
        $siteRunsForThisTurn = !$this->worksWithoutAnOwnerTurn && $this->siteMode === AgentDecisionModelSettings::MODE_ON;
        if ($siteRunsForThisTurn && ($turnEffort === AgentDecisionModelSettings::REASONING_EFFORT_LOW || ($this->purposeBaseEffort === null && $this->configuredEffort === null))) {
            return AgentReasoningEffortDecision::FLOOR_SOURCE_DEFAULT;
        }
        return $this->purposeBaseEffort !== null ? AgentReasoningEffortDecision::FLOOR_SOURCE_PURPOSE : AgentReasoningEffortDecision::FLOOR_SOURCE_CONFIGURED;
    }

    /**
     * The turn's ACTIVE effort and its source, pure: the highest of the base (the configured effort, else the site
     * default), the row pin, the purpose floor and the plan item's minimum, clamped to the ceiling. On a tie the more
     * specific declaration names the source (plan item > purpose > pin > base).
     */
    public static function resolveFloorEffort(?string $configuredEffort, string $defaultEffort, ?string $pinnedEffort, ?string $purposeMinimum, ?string $planItemMinimum, string $ceilingEffort): AgentReasoningEffortFloor
    {
        $effort = $configuredEffort ?? $defaultEffort;
        $source = $configuredEffort !== null ? AgentReasoningEffortDecision::FLOOR_SOURCE_CONFIGURED : AgentReasoningEffortDecision::FLOOR_SOURCE_DEFAULT;
        foreach ([AgentReasoningEffortDecision::FLOOR_SOURCE_PIN => $pinnedEffort, AgentReasoningEffortDecision::FLOOR_SOURCE_PURPOSE => $purposeMinimum, AgentReasoningEffortDecision::FLOOR_SOURCE_PLAN_ITEM => $planItemMinimum] as $candidateSource => $candidate) {
            if ($candidate !== null && AgentDecisionModelSettings::reasoningEffortRank($candidate) >= AgentDecisionModelSettings::reasoningEffortRank($effort)
                && AgentDecisionModelSettings::reasoningEffortRank($candidate) > AgentDecisionModelSettings::reasoningEffortRank($configuredEffort ?? $defaultEffort)) {
                $effort = $candidate;
                $source = $candidateSource;
            }
        }
        return AgentReasoningEffortFloor::create(self::clampTo($effort, $ceilingEffort), $source, $ceilingEffort);
    }

    /**
     * The EFFECTIVE effort, pure: `max(decided, pin)` clamped to the ceiling — a raise never lowers the pin, and
     * nothing exceeds the ceiling. A null decided value (the decision model not asked) means the default.
     */
    public static function resolveEffectiveEffort(?string $decidedEffort, ?string $pinnedEffort, string $ceilingEffort, string $defaultEffort = AgentDecisionModelSettings::REASONING_EFFORT_LOW): string
    {
        $effort = AgentDecisionModelSettings::canonicalReasoningEffort($decidedEffort) ?? $defaultEffort;
        if ($pinnedEffort !== null) {
            $effort = self::raiseTo($effort, $pinnedEffort);
        }
        return self::clampTo($effort, $ceilingEffort);
    }

    /** The higher of two efforts. */
    public static function raiseTo(string $effort, string $atLeast): string
    {
        return AgentDecisionModelSettings::reasoningEffortRank($atLeast) > AgentDecisionModelSettings::reasoningEffortRank($effort) ? $atLeast : $effort;
    }

    /** The lower of an effort and a ceiling; an unknown ceiling clamps nothing. */
    public static function clampTo(string $effort, string $ceilingEffort): string
    {
        $ceilingRank = AgentDecisionModelSettings::reasoningEffortRank($ceilingEffort);
        if ($ceilingRank < 0) {
            return $effort;
        }
        return AgentDecisionModelSettings::reasoningEffortRank($effort) > $ceilingRank ? $ceilingEffort : $effort;
    }
}
