<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The decision model's answer to one question of a probe request
 * ({@see \DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentProbeDecision::$probeAnswers}): the probability
 * of a yes/no question, or the picked choice of a choice question with its confidence.
 *
 * @phpstan-consistent-constructor
 */
class AgentProbeDecisionAnswer extends ValueObject
{
    /** @var string The name of the question the answer belongs to */
    public string $questionName = '';

    /** @var float|null The probability of "yes" of a yes/no question; null for a choice question */
    public ?float $yesProbability = null;

    /** @var string|null The picked choice of a choice question, lower case; null for a yes/no question */
    public ?string $choice = null;

    /** @var float|null The confidence of the picked choice; null for a yes/no question */
    public ?float $confidence = null;

    /**
     * @param string $questionName
     * @param float $yesProbability
     * @return static The answer to a yes/no question
     */
    public static function forYesProbability(string $questionName, float $yesProbability): static
    {
        $probeDecisionAnswer = new static();
        $probeDecisionAnswer->questionName = $questionName;
        $probeDecisionAnswer->yesProbability = $yesProbability;
        return $probeDecisionAnswer;
    }

    /**
     * @param string $questionName
     * @param string $choice
     * @param float $confidence
     * @return static The answer to a choice question
     */
    public static function forChoice(string $questionName, string $choice, float $confidence): static
    {
        $probeDecisionAnswer = new static();
        $probeDecisionAnswer->questionName = $questionName;
        $probeDecisionAnswer->choice = $choice;
        $probeDecisionAnswer->confidence = $confidence;
        return $probeDecisionAnswer;
    }

    /** The answer is the probability of a yes/no question. */
    public function isYesProbability(): bool
    {
        return $this->yesProbability !== null;
    }

    /** @return string The answer on one line: `0.83` for a yes/no question, `choice (0.91)` for a choice question */
    public function toPrintedText(): string
    {
        if ($this->yesProbability !== null) {
            return number_format($this->yesProbability, 2);
        }
        return $this->choice . ' (' . number_format((float)$this->confidence, 2) . ')';
    }

    /** The key of a question's answer: the question's name. */
    public static function uniqueKeyOfQuestion(string $questionName): string
    {
        return self::class . '_' . $questionName;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyOfQuestion($this->questionName);
    }
}
