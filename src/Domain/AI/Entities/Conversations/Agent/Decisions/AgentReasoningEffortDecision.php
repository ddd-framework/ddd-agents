<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;


/**
 * The REASONING-EFFORT decision of ONE owner turn: which effort (`low` / `medium` / `high`) every model
 * call of this turn is sent with. Default `low`; RAISED only when the owner message carries unresolved reasoning
 * work — a dispute of what the assistant claimed, a rejection of the space's proposed identity or class (`high`),
 * a demand for a different course, a conflict between two stored values that must be weighed (`medium`). The four
 * typed facts come from the folded turn-start request ({@see \DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision});
 * the effort itself is resolved in PHP by {@see resolveEffortFromAnswers()} and never asked.
 *
 * Before the decision model is asked at all, PHP judges the PRECONDITION ({@see $preconditionReason}): a short message with no
 * identity/class proposal before it, no stored disagreement and no customer-visible failure in the previous turn is
 * `low` without a call. A raise never lowers a pinned floor (the conversation's row), and the ceiling clamps every
 * value. Every fallback sends the model's CONFIGURED effort, never `low` — an outage must not degrade the turn.
 * The effort arithmetic over the catalog, the purpose, the pin and the plan item is {@see AgentReasoningEffortLimits}'s.
 *
 * Persisted as its own slice on the conversation's {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState}
 * (ONE writer: the turn-start decision step, via
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}); read by
 * the loop's {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService::performModelCall()} on every chunk of
 * the same owner turn.
 */
class AgentReasoningEffortDecision extends AgentDecision
{
    /** The precondition held nothing worth asking — the default effort applies without a call (applied in mode `on`). */
    public const string DECIDED_BY_PRECONDITION = 'precondition';

    // --- precondition reasons (why the decision model was asked, or why not) ---
    public const string PRECONDITION_NONE = 'none';
    public const string PRECONDITION_WORD_FLOOR = 'wordFloor';
    public const string PRECONDITION_IDENTITY_REPLY = 'identityReply';
    public const string PRECONDITION_DISAGREEMENT = 'disagreement';
    public const string PRECONDITION_RECENT_FAILURE = 'recentFailure';

    /** The turn's active effort already equals the ceiling — no raise is possible, the decision model is not asked. */
    public const string PRECONDITION_AT_CEILING = 'atCeiling';

    public const string FLOOR_SOURCE_CONFIGURED = 'configured';
    public const string FLOOR_SOURCE_DEFAULT = 'default';
    public const string FLOOR_SOURCE_PIN = 'pin';
    public const string FLOOR_SOURCE_PURPOSE = 'purpose';
    public const string FLOOR_SOURCE_PLAN_ITEM = 'planItem';
    /** A fixed effort (a measurement setting) — a baseline source only; the site never runs beside one. */
    public const string FLOOR_SOURCE_FIXED = 'fixed';

    /** The owner message admits the effort questions from this many words on (swept 8–12 in the probe). */
    public const int MINIMUM_OWNER_MESSAGE_WORDS = 10;

    /** The `high` questions' floor (a prior until the probe freezes it). */
    public const float MINIMUM_HIGH_PROBABILITY = 0.85;

    /** The `medium` questions' floor. */
    public const float MINIMUM_MEDIUM_PROBABILITY = 0.90;

    /** The ceiling every value is clamped to when the catalog names none (`agenticUseCase.maxReasoningEffort`). */
    public const string DEFAULT_CEILING = AgentDecisionModelSettings::REASONING_EFFORT_HIGH;

    /** @var string PRECONDITION_* — the structural reason the decision model was asked (`none` = not asked). */
    public string $preconditionReason = self::PRECONDITION_NONE;

    /** @var string|null The model's configured agentic effort (what the `off` arm and every fallback send). */
    public ?string $configuredEffort = null;

    /** @var string The site's default effort (`low`). */
    public string $defaultEffort = AgentDecisionModelSettings::REASONING_EFFORT_LOW;

    /** @var string The ceiling the decision was clamped to. */
    public string $ceilingEffort = self::DEFAULT_CEILING;

    /** @var string|null The conversation's pinned floor (the row), null = none. */
    public ?string $pinnedEffort = null;

    /**
     * @var string|null The ACTIVE effort of the turn before any decision — the maximum of the configured effort (or the
     * site default), the purpose's `minReasoningEffort`, the next open plan item's and the row pin, clamped to the
     * ceiling (the decision model has to know which effort is active by default). The decision model
     * judges only raises ABOVE it; the medium questions are not sent when it already is `medium` or higher.
     */
    public ?string $floorEffort = null;

    /** @var string|null What set {@see $floorEffort}: `configured`, `default`, `pin`, `purpose` or `planItem`. */
    public ?string $floorSource = null;

    /** @var string|null The effort the answers resolved to BEFORE the pin and the ceiling (null when the decision model was not asked). */
    public ?string $resolvedEffort = null;

    /** @var string|null The effort the turn's model calls are SENT with when {@see isApplied()}. */
    public ?string $effectiveEffort = null;

    /** The key a working state stored before the rename carries for {@see $rejectsIdentityOrClassProbability}; read, never written. */
    public const string FORMER_STORED_KEY_OF_REJECTS_IDENTITY_OR_CLASS_PROBABILITY = 'rejectsIdentityOrBusinessClassProbability';

    public ?float $disputesWhatTheAssistantClaimsProbability = null;
    public ?float $rejectsIdentityOrClassProbability = null;
    public ?float $asksForADifferentCourseProbability = null;
    public ?float $requiresWeighingConflictingRequirementsProbability = null;

    /** @var string|null The reason the loop fell back to the configured effort, when it did. */
    public ?string $fallbackReason = null;

    /** True when the loop sends {@see $effectiveEffort} instead of the configured effort. */
    public function isApplied(): bool
    {
        return in_array($this->decidedBy, [self::DECIDED_BY_DECISION_MODEL, self::DECIDED_BY_PRECONDITION], true)
            && $this->effectiveEffort !== null;
    }

    /**
     * The PRECONDITION, pure: does the owner turn admit the effort questions at all? Short confirmations, clicks and
     * one-liners are `low` without a call unless the previous assistant turn proposed or saved the identity / class,
     * PHP holds a disagreement, or the previous turn ended in a customer-visible failure.
     */
    public static function resolvePreconditionReason(
        string $ownerMessage,
        bool $previousTurnProposedIdentityOrClass,
        bool $hasDisagreement,
        bool $previousTurnFailedVisibly,
        int $minimumWords = self::MINIMUM_OWNER_MESSAGE_WORDS
    ): string {
        if ($previousTurnProposedIdentityOrClass) {
            return self::PRECONDITION_IDENTITY_REPLY;
        }
        if ($hasDisagreement) {
            return self::PRECONDITION_DISAGREEMENT;
        }
        if ($previousTurnFailedVisibly) {
            return self::PRECONDITION_RECENT_FAILURE;
        }
        return self::countWords($ownerMessage) >= $minimumWords ? self::PRECONDITION_WORD_FLOOR : self::PRECONDITION_NONE;
    }

    /** Words = whitespace-separated tokens that carry at least one letter or digit (a lone "—" or "😅" is not a word). */
    public static function countWords(string $text): int
    {
        $count = 0;
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $token) {
            if (preg_match('/[\p{L}\p{N}]/u', $token) === 1) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * The effort the four answers resolve to, pure: `high` when either high question clears its floor,
     * else `medium` when either medium question clears its floor, else the default. A null probability (question not
     * sent, e.g. the disagreement question without a disagreement) never raises.
     */
    public static function resolveEffortFromAnswers(
        ?float $disputesProbability,
        ?float $rejectsIdentityProbability,
        ?float $differentCourseProbability,
        ?float $weighsConflictProbability,
        string $defaultEffort = AgentDecisionModelSettings::REASONING_EFFORT_LOW,
        float $minimumHighProbability = self::MINIMUM_HIGH_PROBABILITY,
        float $minimumMediumProbability = self::MINIMUM_MEDIUM_PROBABILITY
    ): string {
        if (($disputesProbability ?? 0.0) >= $minimumHighProbability || ($rejectsIdentityProbability ?? 0.0) >= $minimumHighProbability) {
            return AgentReasoningEffortLimits::raiseTo($defaultEffort, AgentDecisionModelSettings::REASONING_EFFORT_HIGH);
        }
        if (($differentCourseProbability ?? 0.0) >= $minimumMediumProbability || ($weighsConflictProbability ?? 0.0) >= $minimumMediumProbability) {
            return AgentReasoningEffortLimits::raiseTo($defaultEffort, AgentDecisionModelSettings::REASONING_EFFORT_MEDIUM);
        }
        return $defaultEffort;
    }

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'decidedAt' => $this->decidedAt,
            'decidedBy' => $this->decidedBy,
            'preconditionReason' => $this->preconditionReason,
            'configuredEffort' => $this->configuredEffort,
            'defaultEffort' => $this->defaultEffort,
            'ceilingEffort' => $this->ceilingEffort,
            'pinnedEffort' => $this->pinnedEffort,
            'floorEffort' => $this->floorEffort,
            'floorSource' => $this->floorSource,
            'resolvedEffort' => $this->resolvedEffort,
            'effectiveEffort' => $this->effectiveEffort,
            'disputesWhatTheAssistantClaimsProbability' => $this->disputesWhatTheAssistantClaimsProbability,
            'rejectsIdentityOrClassProbability' => $this->rejectsIdentityOrClassProbability,
            'asksForADifferentCourseProbability' => $this->asksForADifferentCourseProbability,
            'requiresWeighingConflictingRequirementsProbability' => $this->requiresWeighingConflictingRequirementsProbability,
            'modelVersion' => $this->modelVersion,
            'fallbackReason' => $this->fallbackReason,
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
        $this->preconditionReason = $readString('preconditionReason') ?? self::PRECONDITION_NONE;
        $this->configuredEffort = $readString('configuredEffort');
        $this->defaultEffort = $readString('defaultEffort') ?? AgentDecisionModelSettings::REASONING_EFFORT_LOW;
        $this->ceilingEffort = $readString('ceilingEffort') ?? self::DEFAULT_CEILING;
        $this->pinnedEffort = $readString('pinnedEffort');
        $this->floorEffort = $readString('floorEffort');
        $this->floorSource = $readString('floorSource');
        $this->resolvedEffort = $readString('resolvedEffort');
        $this->effectiveEffort = $readString('effectiveEffort');
        $this->disputesWhatTheAssistantClaimsProbability = $readFloat('disputesWhatTheAssistantClaimsProbability');
        $this->rejectsIdentityOrClassProbability = $readFloat('rejectsIdentityOrClassProbability')
            ?? $readFloat(self::FORMER_STORED_KEY_OF_REJECTS_IDENTITY_OR_CLASS_PROBABILITY);
        $this->asksForADifferentCourseProbability = $readFloat('asksForADifferentCourseProbability');
        $this->requiresWeighingConflictingRequirementsProbability = $readFloat('requiresWeighingConflictingRequirementsProbability');
        $this->modelVersion = $readString('modelVersion');
        $this->fallbackReason = $readString('fallbackReason');
    }
}
