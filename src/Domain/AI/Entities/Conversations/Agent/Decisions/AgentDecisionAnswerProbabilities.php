<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The probability distribution a decision model reported over the answers of ONE `choice` question, VALIDATED:
 * every answer one the question allowed, every value a finite number in [0, 1], the sum
 * within a tolerance of 1. Built only through {@see fromReported()}, which returns null for an unusable distribution,
 * so a site that holds one never re-checks it. The questions the sites ask of it: the probability of one answer, the
 * top answer, its margin over the second, whether an answer is the UNIQUE top choice.
 *
 * Users: the skill preload (the best non-`none` skill and its margin), the stall recovery (the move to apply). The
 * plan bookkeeping reads one verdict's reported probability WITHOUT validating the distribution
 * ({@see readClampedReportedProbabilityOf()}) — validating it there would change the verdicts on an unusable
 * distribution.
 *
 * The ranking is a stable descending sort: equal probabilities keep the order the model reported them in.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionAnswerProbabilities extends ValueObject
{
    /** |sum(probabilities) − 1| tolerance of a valid distribution. */
    public const float DEFAULT_SUM_TOLERANCE = 0.05;

    /** @var array<string, float> answer (lowercased) => probability, in the order the model reported them */
    public array $probabilitiesByAnswer = [];

    /**
     * The reported distribution, cleaned, or null when unusable: empty, an answer outside `$allowedAnswers`, a value
     * that is not a finite number in [0, 1], or a sum farther than `$sumTolerance` from 1. Answers are lowercased
     * and trimmed before the check; two reported keys that fold to the same answer keep the last value and both count
     * to the sum.
     *
     * @param array<string, mixed> $reported
     * @param string[] $allowedAnswers
     */
    public static function fromReported(array $reported, array $allowedAnswers, float $sumTolerance = self::DEFAULT_SUM_TOLERANCE): ?static
    {
        if ($reported === []) {
            return null;
        }
        $allowed = array_fill_keys($allowedAnswers, true);
        $cleaned = [];
        $sum = 0.0;
        foreach ($reported as $key => $value) {
            $key = strtolower(trim((string)$key));
            if (!isset($allowed[$key]) || !is_numeric($value) || !is_finite((float)$value)) {
                return null;
            }
            $probability = (float)$value;
            if ($probability < 0.0 || $probability > 1.0) {
                return null;
            }
            $cleaned[$key] = $probability;
            $sum += $probability;
        }
        if (abs($sum - 1.0) > $sumTolerance) {
            return null;
        }
        $answerProbabilities = new static();
        $answerProbabilities->probabilitiesByAnswer = $cleaned;
        return $answerProbabilities;
    }

    /**
     * One answer's REPORTED probability clamped to [0, 1], read without validating the distribution; null when the
     * answer has no numeric probability.
     *
     * @param array<string, mixed> $reported
     */
    public static function readClampedReportedProbabilityOf(array $reported, string $answer): ?float
    {
        return isset($reported[$answer]) && is_numeric($reported[$answer])
            ? max(0.0, min(1.0, (float)$reported[$answer]))
            : null;
    }

    /** True when the distribution holds no answer (only after {@see withoutAnswer()} removed the last one). */
    public function isEmpty(): bool
    {
        return $this->probabilitiesByAnswer === [];
    }

    /** The probability of `$answer`, null when the distribution does not name it. */
    public function getProbabilityOf(string $answer): ?float
    {
        return $this->probabilitiesByAnswer[$answer] ?? null;
    }

    /** The answer with the highest probability (the first reported on a tie), null when empty. */
    public function getTopAnswer(): ?string
    {
        $rankedAnswers = array_keys($this->rankProbabilities());
        return isset($rankedAnswers[0]) ? (string)$rankedAnswers[0] : null;
    }

    /** The highest probability, 0 when empty. */
    public function getTopProbability(): float
    {
        return (float)(array_values($this->rankProbabilities())[0] ?? 0.0);
    }

    /** The second-highest probability, 0 when fewer than two answers. */
    public function getSecondProbability(): float
    {
        return (float)(array_values($this->rankProbabilities())[1] ?? 0.0);
    }

    /** The top probability minus the second-highest one (minus 0 when only one answer). */
    public function getMarginOverSecond(): float
    {
        return $this->getTopProbability() - $this->getSecondProbability();
    }

    /** True when `$answer` is the top answer AND strictly above the second — a tie is no unique top. */
    public function isUniqueTop(string $answer): bool
    {
        return $this->getTopAnswer() === $answer && $this->getTopProbability() > $this->getSecondProbability();
    }

    /** The same distribution without `$answer` (the sum is not re-checked — the rest is still the model's report). */
    public function withoutAnswer(string $answer): static
    {
        $answerProbabilities = new static();
        $answerProbabilities->probabilitiesByAnswer = $this->probabilitiesByAnswer;
        unset($answerProbabilities->probabilitiesByAnswer[$answer]);
        return $answerProbabilities;
    }

    /** @return array<string, float> answer => probability, in the reported order — the persisted `probabilities` map */
    public function toArray(): array
    {
        return $this->probabilitiesByAnswer;
    }

    /** @return array<string, float> answer => probability, highest first, ties in the reported order */
    protected function rankProbabilities(): array
    {
        $rankedProbabilities = $this->probabilitiesByAnswer;
        arsort($rankedProbabilities);
        return $rankedProbabilities;
    }
}
