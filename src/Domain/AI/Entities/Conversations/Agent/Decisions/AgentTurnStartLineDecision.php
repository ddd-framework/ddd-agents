<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The turn-start line decision of ONE owner turn: does the assistant have to
 * read, search or change something before it can answer — the question rides the folded turn-start request. When it
 * does ({@see announcesTheTurnOfOwnerMessage()}), the customer's activity stack opens with one line on what the
 * assistant does now, written by the activity narrator ({@see \DDD\Domain\AI\Services\Conversations\AgentActivityLinesService}):
 * the decision model answers typed questions only and never writes text, and only generated sentences speak. Small talk, thanks and an answer the assistant gives at once get no line.
 *
 * @phpstan-consistent-constructor
 */
class AgentTurnStartLineDecision extends AgentDecision
{
    /**
     * P(work before the answer) from which the line is shown. `noul` answers are under-confident by measurement, so the
     * floor sits at the middle, not near 1.
     */
    public const float MINIMUM_WORK_BEFORE_ANSWER_PROBABILITY = 0.5;

    public const string SKIP_ANSWERED_AT_ONCE = 'answeredAtOnce';

    /** @var int|null The id of the owner message the line belongs to (the activity element's identity). */
    public ?int $ownerMessageId = null;

    /** @var float|null The model's P(the assistant works before it answers); null when no usable answer. */
    public ?float $workBeforeAnswerProbability = null;

    /** @var bool True when the probability reaches the floor (also recorded under `shadow`). */
    public bool $wouldAnnounce = false;

    /** @var string|null Why no line is shown (SKIP_* or the request's fallback), null when one is. */
    public ?string $skipReason = null;

    /** The decision of an owner turn's start before the model answered, decided now. */
    public static function build(int $ownerMessageIndex, int $ownerMessageId): static
    {
        $decision = new static();
        $decision->ownerMessageIndex = $ownerMessageIndex;
        $decision->ownerMessageId = $ownerMessageId;
        $decision->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
        return $decision;
    }

    /** True when the loop acts on the answer (site `on` and a usable answer). */
    public function isApplied(): bool
    {
        return $this->decidedBy === self::DECIDED_BY_DECISION_MODEL;
    }

    /** Read the probability: the line is due at or above the floor. */
    public function resolveFromWorkBeforeAnswerProbability(?float $workBeforeAnswerProbability): void
    {
        $this->workBeforeAnswerProbability = $workBeforeAnswerProbability;
        $this->wouldAnnounce = $workBeforeAnswerProbability !== null && $workBeforeAnswerProbability >= self::MINIMUM_WORK_BEFORE_ANSWER_PROBABILITY;
        $this->skipReason = $this->wouldAnnounce ? null : self::SKIP_ANSWERED_AT_ONCE;
    }

    /** True when the activity stack of the turn this owner message started opens with the line. */
    public function announcesTheTurnOfOwnerMessage(int $ownerMessageIndex): bool
    {
        return $this->isApplied() && $this->wouldAnnounce && $this->ownerMessageIndex === $ownerMessageIndex;
    }

    /**
     * The resolved consequence, for the decision record and the turn event.
     *
     * @return array<string, mixed>
     */
    public function buildOutcome(string $siteMode): array
    {
        return [
            'applied' => $this->isApplied() && $this->wouldAnnounce,
            'mode' => $siteMode,
            'workBeforeAnswerProbability' => $this->workBeforeAnswerProbability,
            'wouldAnnounce' => $this->wouldAnnounce,
            'skipReason' => $this->skipReason,
            'minimumWorkBeforeAnswerProbability' => self::MINIMUM_WORK_BEFORE_ANSWER_PROBABILITY,
        ];
    }

    /** @return array<string, mixed> */
    public function mapToRepository(): mixed
    {
        return [
            'ownerMessageIndex' => $this->ownerMessageIndex,
            'ownerMessageId' => $this->ownerMessageId,
            'decidedAt' => $this->decidedAt,
            'decidedBy' => $this->decidedBy,
            'workBeforeAnswerProbability' => $this->workBeforeAnswerProbability,
            'wouldAnnounce' => $this->wouldAnnounce,
            'skipReason' => $this->skipReason,
            'modelVersion' => $this->modelVersion,
        ];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $repoArray = is_array($repoObject) ? $repoObject : [];
        $this->ownerMessageIndex = isset($repoArray['ownerMessageIndex']) && is_numeric($repoArray['ownerMessageIndex']) ? (int)$repoArray['ownerMessageIndex'] : null;
        $this->ownerMessageId = isset($repoArray['ownerMessageId']) && is_numeric($repoArray['ownerMessageId']) ? (int)$repoArray['ownerMessageId'] : null;
        $this->decidedAt = self::readNonEmptyString($repoArray, 'decidedAt');
        $this->decidedBy = self::readNonEmptyString($repoArray, 'decidedBy');
        $this->workBeforeAnswerProbability = isset($repoArray['workBeforeAnswerProbability']) && is_numeric($repoArray['workBeforeAnswerProbability']) ? (float)$repoArray['workBeforeAnswerProbability'] : null;
        $this->wouldAnnounce = (bool)($repoArray['wouldAnnounce'] ?? false);
        $this->skipReason = self::readNonEmptyString($repoArray, 'skipReason');
        $this->modelVersion = self::readNonEmptyString($repoArray, 'modelVersion');
    }

    /** @param array<string, mixed> $repoArray */
    protected static function readNonEmptyString(array $repoArray, string $key): ?string
    {
        $value = trim((string)($repoArray[$key] ?? ''));
        return $value !== '' ? $value : null;
    }
}
