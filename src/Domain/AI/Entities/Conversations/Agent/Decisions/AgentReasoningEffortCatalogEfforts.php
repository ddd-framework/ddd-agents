<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The reasoning efforts the model catalog declares for ONE conversation's agent model: the configured agentic effort
 * and the ceiling. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentReasoningEffortDecisionsService::resolveConfiguredAndCeilingReasoningEffort()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentReasoningEffortCatalogEfforts extends ValueObject
{
    /** @var string|null The catalog's `reasoningEffort` of the agent model; null for a model without one */
    public ?string $configuredEffort = null;

    /** @var string The catalog's `maxReasoningEffort`, else the default ceiling */
    public string $ceilingEffort = AgentReasoningEffortDecision::DEFAULT_CEILING;

    /**
     * @param string|null $configuredEffort
     * @param string $ceilingEffort
     * @return static
     */
    public static function forConfiguredAndCeiling(?string $configuredEffort, string $ceilingEffort): static
    {
        $catalogEfforts = new static();
        $catalogEfforts->configuredEffort = $configuredEffort;
        $catalogEfforts->ceilingEffort = $ceilingEffort;
        return $catalogEfforts;
    }
}
