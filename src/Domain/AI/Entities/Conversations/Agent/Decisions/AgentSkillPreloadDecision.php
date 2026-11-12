<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Skills\AdmittedAISkills;

/**
 * The SKILL-PRELOAD decision of ONE owner-text turn (site B): before the first model call, which
 * offered skill is the one to load — so the chat model starts with it and spends no call on `load_skills`. The typed
 * fact comes from the folded turn-start request ({@see \DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision}
 * carries the question); the consequence is resolved in PHP by {@see resolvePreloadFromDistribution()} and never asked.
 *
 * The decision is about the SKILL alone: a skill carries the working rules of its domain and may
 * bring tools, so whether a tool will be called is no condition of loading it. When the decision model names a skill
 * with the confidence and the margin below, it is loaded. Formerly a second question asked whether the turn
 * needs a tool and gated the pick; it refused the right pick on requests that read like advice.
 *
 * The rule: over a VALIDATED probability distribution (keys ⊆ tokens ∪ none, finite, sum ≈ 1) the
 * best non-`none` entry is the pick at ≥ 0.60 while `none` stays ≤ 0.40. ONE skill per pass.
 *
 * A stamp with {@see $decidedBy} = {@see DECIDED_BY_DECISION_MODEL} is APPLIED by the loop (mode `on` for the conversation);
 * every other value is a record only. The answer set is the CANONICAL skill names (opaque
 * `skill_<n>` tokens carried no information — the names do); the wire lowercases choices, so {@see $catalogTokens} maps
 * the lowercased choice back to the canonical name.
 */
class AgentSkillPreloadDecision extends AgentDecision
{
    /** The abstention token of the `skill` question. */
    public const string ANSWER_NONE = 'none';

    /** Written by the former tool-need gate; stored records carry it, nothing produces it any more. */
    public const string SKIP_NOT_NEEDED = 'notNeeded';
    public const string SKIP_ABSTAINED = 'abstained';
    public const string SKIP_LOW_CONFIDENCE = 'lowConfidence';
    /** Written by the former pick-margin floor; stored records carry it, nothing produces it any more. */
    public const string SKIP_LOW_MARGIN = 'lowMargin';
    public const string SKIP_INVALID_DISTRIBUTION = 'invalidDistribution';
    public const string SKIP_NOT_OFFERED = AdmittedAISkills::REJECTED_NOT_OFFERED;

    /** The cap on skill-preload passes per turn (the roots pass + follow-ups under a root that unlocked sub-skills). */
    public const int MAX_PASSES = 3;

    /** The admission write threw and rolled back — the turn runs without the preload. */
    public const string SKIP_ADMISSION_ERROR = 'admissionError';

    /** @var int The pass within the turn: 1 = the roots; 2+ = the sub-skills a root admitted in the pass before unlocked. */
    public int $pass = 1;

    /** @var string|null Pass 2+: the root whose admission unlocked this pass's answer set. */
    public ?string $unlockedByRootSkillName = null;

    /** @var string|null The arm of the conversation's account under the `cohort` site mode (`control` / `shadow` / `on`), else null. */
    public ?string $arm = null;

    /** @var string|null The criteria version the catalog was rendered with. */
    public ?string $criteriaVersion = null;

    /** @var array<string, string> The lowercased answer choice → canonical skill name as sent (the ownership snapshot lives on the event). */
    public array $catalogTokens = [];

    /** @var array<string, float> Old records only: the former rerank pass's fit per re-judged candidate (canonical name → P(fit)). Never set any more. */
    public array $rerankFits = [];

    /** @var bool Old records only: true when the former rerank pass made the pick. Never set any more. */
    public bool $pickedByRerank = false;

    /** @var float|null Old records only: the answer of the former tool-need question. Never set any more. */
    public ?float $needsToolWorkProbability = null;

    /** @var string|null The picked token (`skill_n` / `none`), as the model answered it. */
    public ?string $pickedToken = null;

    /** @var string|null The picked SKILL NAME, resolved through {@see $catalogTokens}; null = none / unknown. */
    public ?string $pickedSkillName = null;

    /** @var float|null The pick's confidence as reported. */
    public ?float $pickConfidence = null;

    /** @var array<string, float> The reported distribution over the tokens (validated on read). */
    public array $probabilities = [];

    /** @var float|null top-1 − top-2 of the validated distribution. */
    public ?float $pickMargin = null;

    /** @var bool True when the chain said "preload this root" — applied only when {@see isApplied()}. */
    public bool $wouldPreload = false;

    /** @var bool True when the FULL admission predicate held (floors, offered, not loaded, policy, pressure) — the shadow arm's `wouldApply`. */
    public bool $wouldApply = false;

    /** @var string|null Why no preload happened (SKIP_*, or the admission's rejection reason), null when it did. */
    public ?string $skipReason = null;

    /** @var string[] The names the admission actually promoted. */
    public array $admittedSkillNames = [];

    /** True when the loop must act on {@see $wouldPreload}. */
    public function isApplied(): bool
    {
        return $this->decidedBy === self::DECIDED_BY_DECISION_MODEL;
    }

    /**
     * Whether the preload of the owner message at `$ownerMessageIndex` was APPLIED: the loop acted on this decision
     * ({@see isApplied()} — never the shadow arm's record) and it chose a root or admitted a skill (a pass 2+ follows only
     * an admitted root). The direct-widget site defers to such a turn.
     */
    public function isAppliedPreloadForOwnerMessage(int $ownerMessageIndex): bool
    {
        return $this->ownerMessageIndex === $ownerMessageIndex
            && $this->isApplied()
            && ($this->wouldPreload || $this->admittedSkillNames !== [] || $this->pass > 1);
    }

    /**
     * The reported distribution validated against the answer set of the `skill` question: the offered skills
     * (lowercased choices) and `none`; null when unusable.
     *
     * @param array<string, mixed> $reportedProbabilities
     * @param string[] $tokens the lowercased answer choices of the offered skills
     */
    public static function validateReportedProbabilities(array $reportedProbabilities, array $tokens): ?AgentDecisionAnswerProbabilities
    {
        return AgentDecisionAnswerProbabilities::fromReported($reportedProbabilities, array_merge($tokens, [self::ANSWER_NONE]));
    }

    /**
     * The ONE general preload rule (replaces the wide-pass floors + the dependent rerank
     * request): the distribution alone decides. The best NON-`none` entry is the pick when its probability reaches
     * {@see MINIMUM_TOP_PROBABILITY} and `none` stays at or below {@see MAXIMUM_NONE_PROBABILITY}; otherwise the
     * pass abstains and the chat model loads what it needs itself. Calibrated on 12 259 roots decisions and 2 148
     * sub-skill decisions over one week: 0.6/0.4 keeps 96 % of the roots picks the wide pass + rerank
     * produced (74 % of the sub-skill picks) and adds 6 % of decisions as extra loads; no second request anywhere.
     * Returns whether the pass preloads, why not, the margin and the best token — the picked token is the
     * distribution's own best entry, never the model's `pickedToken` (a weak abstention with a strong runner-up is a
     * pick under this rule).
     *
     * @param AgentDecisionAnswerProbabilities|null $distribution the validated distribution incl. `none`
     * @param float $minimumTopProbability
     * @param float $maximumNoneProbability
     * @return AgentDecisionDistributionPick
     */
    public static function resolvePreloadFromDistribution(
        ?AgentDecisionAnswerProbabilities $distribution,
        float $minimumTopProbability = self::MINIMUM_TOP_PROBABILITY,
        float $maximumNoneProbability = self::MAXIMUM_NONE_PROBABILITY
    ): AgentDecisionDistributionPick {
        if ($distribution === null) {
            return AgentDecisionDistributionPick::forOutcome(false, self::SKIP_INVALID_DISTRIBUTION, null);
        }
        $candidates = $distribution->withoutAnswer(self::ANSWER_NONE);
        if ($candidates->isEmpty()) {
            return AgentDecisionDistributionPick::forOutcome(false, self::SKIP_ABSTAINED, null);
        }
        $topToken = (string)$candidates->getTopAnswer();
        $top = $candidates->getTopProbability();
        $margin = $candidates->getMarginOverSecond();
        $none = $distribution->getProbabilityOf(self::ANSWER_NONE) ?? 0.0;
        if ($none > $maximumNoneProbability) {
            return AgentDecisionDistributionPick::forOutcome(false, self::SKIP_ABSTAINED, $topToken, $margin);
        }
        if ($top < $minimumTopProbability) {
            return AgentDecisionDistributionPick::forOutcome(false, self::SKIP_LOW_CONFIDENCE, $topToken, $margin);
        }
        return AgentDecisionDistributionPick::forOutcome(true, null, $topToken, $margin);
    }

    /** The best non-`none` entry's probability floor of {@see resolvePreloadFromDistribution()}. */
    public const float MINIMUM_TOP_PROBABILITY = 0.60;

    /** The `none` probability ceiling of {@see resolvePreloadFromDistribution()}. */
    public const float MAXIMUM_NONE_PROBABILITY = 0.40;

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'decidedAt' => $this->decidedAt,
            'decidedBy' => $this->decidedBy,
            'pass' => $this->pass,
            'unlockedByRootSkillName' => $this->unlockedByRootSkillName,
            'arm' => $this->arm,
            'criteriaVersion' => $this->criteriaVersion,
            'catalogTokens' => $this->catalogTokens,
            'needsToolWorkProbability' => $this->needsToolWorkProbability,
            'pickedToken' => $this->pickedToken,
            'pickedSkillName' => $this->pickedSkillName,
            'pickConfidence' => $this->pickConfidence,
            'probabilities' => $this->probabilities,
            'pickMargin' => $this->pickMargin,
            'rerankFits' => $this->rerankFits,
            'pickedByRerank' => $this->pickedByRerank,
            'wouldPreload' => $this->wouldPreload,
            'wouldApply' => $this->wouldApply,
            'skipReason' => $this->skipReason,
            'admittedSkillNames' => $this->admittedSkillNames,
            'modelVersion' => $this->modelVersion,
        ];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $repoArray = is_array($repoObject) ? $repoObject : [];
        $readString = static function (string $key) use ($repoArray): ?string {
            $value = trim((string)($repoArray[$key] ?? ''));
            return $value !== '' ? $value : null;
        };
        $readFloat = static function (string $key) use ($repoArray): ?float {
            return isset($repoArray[$key]) && is_numeric($repoArray[$key]) ? (float)$repoArray[$key] : null;
        };
        $this->ownerMessageIndex = isset($repoArray['ownerMessageIndex']) && is_numeric($repoArray['ownerMessageIndex']) ? (int)$repoArray['ownerMessageIndex'] : null;
        $this->decidedAt = $readString('decidedAt');
        $this->decidedBy = $readString('decidedBy');
        $this->pass = isset($repoArray['pass']) && is_numeric($repoArray['pass']) ? max(1, (int)$repoArray['pass']) : 1;
        $this->unlockedByRootSkillName = $readString('unlockedByRootSkillName');
        $this->arm = $readString('arm');
        $this->criteriaVersion = $readString('criteriaVersion');
        $this->catalogTokens = is_array($repoArray['catalogTokens'] ?? null) ? array_map('strval', $repoArray['catalogTokens']) : [];
        $this->needsToolWorkProbability = $readFloat('needsToolWorkProbability');
        $this->pickedToken = $readString('pickedToken');
        $this->pickedSkillName = $readString('pickedSkillName');
        $this->pickConfidence = $readFloat('pickConfidence');
        $this->probabilities = is_array($repoArray['probabilities'] ?? null) ? array_map('floatval', $repoArray['probabilities']) : [];
        $this->pickMargin = $readFloat('pickMargin');
        $this->rerankFits = [];
        foreach ((array)($repoArray['rerankFits'] ?? []) as $rerankName => $rerankFit) {
            if (is_numeric($rerankFit)) {
                $this->rerankFits[(string)$rerankName] = (float)$rerankFit;
            }
        }
        $this->pickedByRerank = (bool)($repoArray['pickedByRerank'] ?? false);
        $this->wouldPreload = (bool)($repoArray['wouldPreload'] ?? false);
        $this->wouldApply = (bool)($repoArray['wouldApply'] ?? false);
        $this->skipReason = $readString('skipReason');
        $this->admittedSkillNames = is_array($repoArray['admittedSkillNames'] ?? null) ? array_values(array_map('strval', $repoArray['admittedSkillNames'])) : [];
        $this->modelVersion = $readString('modelVersion');
    }
}
