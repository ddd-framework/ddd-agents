<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The outcome of one mechanical background operation a hidden background run carried out: whether it failed and the
 * verdict text the run reports to its parent.
 *
 * @phpstan-consistent-constructor
 */
class AgentBackgroundOperationOutcome extends ValueObject
{
    /** @var bool Whether the operation failed. */
    public bool $operationFailed = false;

    /** @var string The verdict text written as the run's result. */
    public string $verdictText = '';

    /**
     * A completed operation.
     *
     * @param string $verdictText
     * @return self
     */
    public static function completed(string $verdictText): self
    {
        $agentBackgroundOperationOutcome = new static();
        $agentBackgroundOperationOutcome->verdictText = $verdictText;
        return $agentBackgroundOperationOutcome;
    }

    /**
     * A failed operation.
     *
     * @param string $verdictText
     * @return self
     */
    public static function failed(string $verdictText): self
    {
        $agentBackgroundOperationOutcome = new static();
        $agentBackgroundOperationOutcome->operationFailed = true;
        $agentBackgroundOperationOutcome->verdictText = $verdictText;
        return $agentBackgroundOperationOutcome;
    }
}
