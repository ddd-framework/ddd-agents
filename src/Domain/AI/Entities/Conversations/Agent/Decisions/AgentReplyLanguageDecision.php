<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The reply-language decision of ONE owner turn: which language the assistant answers in, which
 * SOURCE won, and who decided — persisted as its own slice on the conversation's
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState} (ONE writer: the turn's decision step, via
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::persistAgentWorkingStateSlice()}).
 *
 * The precedence is RECENCY: the youngest non-abstaining source wins, and the owner's
 * words in THIS turn are by definition the youngest — so what the owner asks for now, else writes now, beats the
 * conversation's earlier anchor, a standing directive and the configured default. Anchor and directive are ordered
 * against each other by their own instants. The chain is resolved HERE, in PHP ({@see resolveByRecency()}), from the typed
 * facts a decision model returns — never inside a model question.
 *
 * A stamp with {@see $decidedBy} = {@see DECIDED_BY_DECISION_MODEL} or {@see DECIDED_BY_ANCHOR} is APPLIED by the context build;
 * every other value is a record only (shadow mode, or a fallback that names why the model did not decide and no
 * anchor carried), and the context keeps today's configured language.
 *
 * @phpstan-consistent-constructor
 */
class AgentReplyLanguageDecision extends AgentDecision
{
    public const string SOURCE_REQUEST = 'REQUEST';
    public const string SOURCE_WRITTEN = 'WRITTEN';
    public const string SOURCE_ANCHOR = 'ANCHOR';
    public const string SOURCE_DIRECTIVE = 'DIRECTIVE';
    public const string SOURCE_CONFIG = 'CONFIG';

    /**
     * The model did not answer this turn (timeout, breaker, unparsable) but the chain resolved to the conversation's
     * own ANCHOR — an earlier APPLIED decision. That needs no model call, so in mode `on` it is applied: a provider
     * hiccup must not flip an owner who was answered in Romanian back to the configured language.
     */
    public const string DECIDED_BY_ANCHOR = 'anchor';
    /**
     * A USER-ABSENT run (a scheduled run, an admin task, a run the application starts itself): there is no owner message
     * to measure — the seed is platform-composed text (the schedule's mission, a delivery contract, the previous
     * run's result), and measuring it answered 131 of 259 scheduled runs in English for non-English customers.
     * The platform stamps the space's configured language itself; the model is never asked.
     * Applied like a model decision, so the context build names it in the reply-language cue.
     */
    public const string DECIDED_BY_PLATFORM = 'platform';

    /** The answer-space tokens a decision model uses to abstain — never a language. */
    public const string ANSWER_NONE = 'none';
    public const string ANSWER_UNDECIDABLE = 'undecidable';
    public const string ANSWER_OTHER = 'other';
    public const string ANSWER_CONFLICTING = 'conflicting';

    /** Below this confidence a typed answer counts as an abstention (to be fitted per question type). */
    public const float MINIMUM_CONFIDENCE = 0.9;

    /** @var string|null The language the reply must be written in (lowercase ISO code). */
    public ?string $languageCode = null;

    /** @var string|null Which SOURCE_* the chain applied. */
    public ?string $source = null;

    public ?string $requestedLanguageCode = null;
    public ?float $requestedConfidence = null;
    public ?string $writtenLanguageCode = null;
    public ?float $writtenConfidence = null;
    public ?string $directiveLanguageCode = null;
    public ?float $directiveConfidence = null;

    /** @var string|null The previous turn's decided language (the sticky anchor this chain saw). */
    public ?string $anchorLanguageCode = null;
    public ?string $anchorDecidedAt = null;

    /** @var string|null `validFrom` of the newest current DIRECTIVE memory in scope — the directive's instant. */
    public ?string $directiveValidFrom = null;

    /** @var string|null What {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::resolveUserLanguageCode()} said — the fallback. */
    public ?string $configuredLanguageCode = null;

    /** True when the context build must NAME this decision's language instead of the configured default. */
    public function isApplied(): bool
    {
        return in_array($this->decidedBy, [self::DECIDED_BY_DECISION_MODEL, self::DECIDED_BY_ANCHOR, self::DECIDED_BY_PLATFORM], true)
            && $this->languageCode !== null && $this->languageCode !== '';
    }

    /**
     * The stamp of a USER-ABSENT run ({@see DECIDED_BY_PLATFORM}): the configured language, source CONFIG, applied —
     * pure, no model. `$ownerMessageIndex` is the index of the run's seed message (the per-turn key every reader
     * compares against the newest user message).
     */
    public static function forUserAbsentRun(int $ownerMessageIndex, string $configuredLanguageCode, DateTime $decidedAt): static
    {
        $decision = new static();
        $decision->ownerMessageIndex = $ownerMessageIndex;
        $decision->decidedAt = $decidedAt->format('Y-m-d H:i:s');
        $decision->configuredLanguageCode = $configuredLanguageCode;
        $decision->languageCode = $configuredLanguageCode;
        $decision->source = self::SOURCE_CONFIG;
        $decision->decidedBy = self::DECIDED_BY_PLATFORM;
        return $decision;
    }

    /** True when the language was measured from the owner's own words in the current turn (request or written). */
    public function isMeasuredFromOwnerWords(): bool
    {
        return in_array($this->source, [self::SOURCE_REQUEST, self::SOURCE_WRITTEN], true);
    }

    /**
     * The RECENCY chain, pure. Returns the language code and the source that decided it.
     *
     * 1. A request in this turn (a real code, confidence ≥ the minimum) — youngest, and it outranks the written
     *    language of the same turn ("Antworte bitte auf Rumänisch" written in German → ro).
     * 2. The written language of this turn (a real code, confidence ≥ the minimum) — also now.
     * 3. The younger of the conversation's anchor and the standing directive, by their instants; a source without
     *    an instant ranks oldest of the two; a directive that abstained (`none` / `conflicting` / low confidence)
     *    is skipped.
     * 4. The configured language.
     *
     * `none` / `undecidable` / `other` / `conflicting` are abstentions, never languages: `other` (a requested
     * language the app does not carry) is deliberately NOT applied until a product decision rules on it.
     *
     * @param string|null $requestedLanguageCode
     * @param float $requestedConfidence
     * @param string|null $writtenLanguageCode
     * @param float $writtenConfidence
     * @param string|null $anchorLanguageCode
     * @param DateTime|null $anchorDecidedAt
     * @param string|null $directiveLanguageCode
     * @param float $directiveConfidence
     * @param DateTime|null $directiveValidFrom
     * @param string $configuredLanguageCode
     * @param string[] $supportedLanguageCodes the closed answer set; a code outside it is treated as an abstention
     * @param float $minimumConfidence
     * @return AgentReplyLanguageResolution
     */
    public static function resolveByRecency(
        ?string $requestedLanguageCode,
        float $requestedConfidence,
        ?string $writtenLanguageCode,
        float $writtenConfidence,
        ?string $anchorLanguageCode,
        ?DateTime $anchorDecidedAt,
        ?string $directiveLanguageCode,
        float $directiveConfidence,
        ?DateTime $directiveValidFrom,
        string $configuredLanguageCode,
        array $supportedLanguageCodes,
        float $minimumConfidence = self::MINIMUM_CONFIDENCE
    ): AgentReplyLanguageResolution {
        if (self::isDecisiveLanguage($requestedLanguageCode, $requestedConfidence, $supportedLanguageCodes, $minimumConfidence)) {
            return AgentReplyLanguageResolution::forLanguageCodeAndSource($requestedLanguageCode, self::SOURCE_REQUEST);
        }
        if (self::isDecisiveLanguage($writtenLanguageCode, $writtenConfidence, $supportedLanguageCodes, $minimumConfidence)) {
            return AgentReplyLanguageResolution::forLanguageCodeAndSource($writtenLanguageCode, self::SOURCE_WRITTEN);
        }
        $anchorIsUsable = self::isDecisiveLanguage($anchorLanguageCode, 1.0, $supportedLanguageCodes, $minimumConfidence);
        $directiveIsUsable = self::isDecisiveLanguage($directiveLanguageCode, $directiveConfidence, $supportedLanguageCodes, $minimumConfidence);
        if ($anchorIsUsable && $directiveIsUsable) {
            // Both present: the younger instant wins; a missing instant ranks as the older of the two.
            $anchorInstant = $anchorDecidedAt?->getTimestamp() ?? PHP_INT_MIN;
            $directiveInstant = $directiveValidFrom?->getTimestamp() ?? PHP_INT_MIN;
            return $directiveInstant > $anchorInstant
                ? AgentReplyLanguageResolution::forLanguageCodeAndSource($directiveLanguageCode, self::SOURCE_DIRECTIVE)
                : AgentReplyLanguageResolution::forLanguageCodeAndSource($anchorLanguageCode, self::SOURCE_ANCHOR);
        }
        if ($anchorIsUsable) {
            return AgentReplyLanguageResolution::forLanguageCodeAndSource($anchorLanguageCode, self::SOURCE_ANCHOR);
        }
        if ($directiveIsUsable) {
            return AgentReplyLanguageResolution::forLanguageCodeAndSource($directiveLanguageCode, self::SOURCE_DIRECTIVE);
        }
        return AgentReplyLanguageResolution::forLanguageCodeAndSource($configuredLanguageCode, self::SOURCE_CONFIG);
    }

    /**
     * A FIRST owner message of one bare token ("slots", "Hola", a name) carries no language evidence the chain may act
     * on: the decision model reads an English product term as English with full confidence,
     * and there is no anchor to hold against it. Deterministic, before the model's answer is weighed: the written
     * answer counts as an abstention and the configured language applies. Once an anchor exists the model's context
     * question decides (a bare term after a German offer is `undecidable` by its own criteria).
     */
    public static function writtenEvidenceIsInconclusiveForFirstMessage(?string $anchorLanguageCode, string $ownerWordsThisTurn): bool
    {
        if ($anchorLanguageCode !== null) {
            return false;
        }
        $tokens = preg_split('/\s+/u', trim($ownerWordsThisTurn)) ?: [];
        return count(array_filter($tokens, static fn (string $token): bool => $token !== '')) < 2;
    }

    /** A real language code from the closed set, at or above the confidence floor — not an abstention token. */
    public static function isDecisiveLanguage(?string $languageCode, float $confidence, array $supportedLanguageCodes, float $minimumConfidence): bool
    {
        if ($languageCode === null || $languageCode === '' || $confidence < $minimumConfidence) {
            return false;
        }
        if (in_array($languageCode, [self::ANSWER_NONE, self::ANSWER_UNDECIDABLE, self::ANSWER_OTHER, self::ANSWER_CONFLICTING], true)) {
            return false;
        }
        return in_array($languageCode, $supportedLanguageCodes, true);
    }

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'languageCode' => $this->languageCode,
            'source' => $this->source,
            'decidedBy' => $this->decidedBy,
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'decidedAt' => $this->decidedAt,
            'requestedLanguageCode' => $this->requestedLanguageCode,
            'requestedConfidence' => $this->requestedConfidence,
            'writtenLanguageCode' => $this->writtenLanguageCode,
            'writtenConfidence' => $this->writtenConfidence,
            'directiveLanguageCode' => $this->directiveLanguageCode,
            'directiveConfidence' => $this->directiveConfidence,
            'anchorLanguageCode' => $this->anchorLanguageCode,
            'anchorDecidedAt' => $this->anchorDecidedAt,
            'directiveValidFrom' => $this->directiveValidFrom,
            'configuredLanguageCode' => $this->configuredLanguageCode,
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
        $this->languageCode = $readString('languageCode');
        $this->source = $readString('source');
        $this->decidedBy = $readString('decidedBy');
        $this->ownerMessageIndex = isset($repoArray['ownerMessageIndex']) && is_numeric($repoArray['ownerMessageIndex'])
            ? (int)$repoArray['ownerMessageIndex']
            : null;
        $this->decidedAt = $readString('decidedAt');
        $this->requestedLanguageCode = $readString('requestedLanguageCode');
        $this->requestedConfidence = $readFloat('requestedConfidence');
        $this->writtenLanguageCode = $readString('writtenLanguageCode');
        $this->writtenConfidence = $readFloat('writtenConfidence');
        $this->directiveLanguageCode = $readString('directiveLanguageCode');
        $this->directiveConfidence = $readFloat('directiveConfidence');
        $this->anchorLanguageCode = $readString('anchorLanguageCode');
        $this->anchorDecidedAt = $readString('anchorDecidedAt');
        $this->directiveValidFrom = $readString('directiveValidFrom');
        $this->configuredLanguageCode = $readString('configuredLanguageCode');
        $this->modelVersion = $readString('modelVersion');
    }
}
