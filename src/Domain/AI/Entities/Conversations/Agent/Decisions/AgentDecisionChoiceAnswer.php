<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The decision model's answer to ONE `choice` question as the decision request reads it: the picked choice (lower
 * case, already held to the question's allowed choices), the model's confidence in it and, for a question whose
 * distribution matters, the probabilities the model reported. Built by
 * {@see \DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisionTrait::readChoiceFrom()} and
 * {@see \DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisionTrait::readChoiceWithProbabilitiesFrom()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionChoiceAnswer extends ValueObject
{
    /** @var string|null The picked choice, lower case; null when the answer is absent or malformed */
    public ?string $choice = null;

    /** @var float The model's confidence in the picked choice; 0 when it reported none */
    public float $confidence = 0.0;

    /**
     * @var array<string, mixed> The probabilities the model reported, keyed by the lowercased answer and not yet
     *      validated; empty when the question's distribution is not read or the model reported none
     */
    public array $reportedProbabilities = [];

    /**
     * @param string|null $choice
     * @param float $confidence
     * @param array<string, mixed> $reportedProbabilities
     * @return static
     */
    public static function forChoice(?string $choice, float $confidence, array $reportedProbabilities = []): static
    {
        $decisionChoiceAnswer = new static();
        $decisionChoiceAnswer->choice = $choice;
        $decisionChoiceAnswer->confidence = $confidence;
        $decisionChoiceAnswer->reportedProbabilities = $reportedProbabilities;
        return $decisionChoiceAnswer;
    }
}
