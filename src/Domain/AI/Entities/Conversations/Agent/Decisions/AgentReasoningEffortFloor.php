<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A turn's ACTIVE reasoning effort before any decision, with the declaration that set it and the ceiling it is clamped
 * to — what the decision model is told the assistant already reasons at
 * ({@see AgentReasoningEffortLimits::buildFloor()}, {@see AgentReasoningEffortLimits::resolveFloorEffort()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentReasoningEffortFloor extends ValueObject
{
    /** @var string The active effort, clamped to {@see $ceilingEffort}. */
    public string $effort = AgentDecisionModelSettings::REASONING_EFFORT_LOW;

    /** @var string What set {@see $effort}: an {@see AgentReasoningEffortDecision}::FLOOR_SOURCE_*. */
    public string $source = AgentReasoningEffortDecision::FLOOR_SOURCE_DEFAULT;

    /** @var string The ceiling no effort exceeds. */
    public string $ceilingEffort = AgentReasoningEffortDecision::DEFAULT_CEILING;

    public static function create(string $effort, string $source, string $ceilingEffort): static
    {
        $agentReasoningEffortFloor = new static();
        $agentReasoningEffortFloor->effort = $effort;
        $agentReasoningEffortFloor->source = $source;
        $agentReasoningEffortFloor->ceilingEffort = $ceilingEffort;
        return $agentReasoningEffortFloor;
    }
}
