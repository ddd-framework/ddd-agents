<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentToolResultInjectionDecision;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the decision model answered for ONE tool result: the highest injection probability
 * over its chunks, how many chunks were answered, what the questions cost and how long they took. The screening job
 * applies it; the screening probe reports it.
 */
class ToolResultInjectionScreeningOutcome extends ValueObject
{
    public ?float $highestInjectionProbability = null;

    public int $chunkCount = 0;

    public int $answeredChunkCount = 0;

    /** What the questions cost, as the decision model booked it. */
    public float $costUsd = 0.0;

    public int $elapsedMs = 0;

    /** The first error that kept a chunk unanswered (the reason a verdict is `failed`); null when none. */
    public ?string $errorMessage = null;

    /** Whether the result carries an instruction aimed at an AI by the screening's threshold. */
    public function flagsInjection(): bool
    {
        return $this->highestInjectionProbability !== null
            && $this->highestInjectionProbability >= ArgusAgentToolResultInjectionDecision::MINIMUM_INJECTION_PROBABILITY;
    }

    /** Whether every chunk was answered (a verdict can be `clean` only then). */
    public function isEveryChunkAnswered(): bool
    {
        return $this->answeredChunkCount === $this->chunkCount;
    }
}
