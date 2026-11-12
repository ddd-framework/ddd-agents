<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Models\CustomerSelection;

use DDD\Domain\AI\Entities\Models\Selection\AgentEligibleModel;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE agent-eligible model that carries an `agentCustomerSelection` config block, paired with that block — the
 * intermediate step of {@see AgentCustomerSelectableModels::fromAgentEligibleModels()}, which builds one per curated
 * model, orders them by {@see getDisplayOrder()} and turns each into an {@see AgentCustomerSelectableModel}.
 *
 * @phpstan-consistent-constructor
 */
class AgentCustomerCuratedModel extends ValueObject
{
    /** @var array<string, mixed> The model's `agentCustomerSelection` config block, as configured */
    public array $customerSelectionConfig = [];

    /** @var AgentEligibleModel The agent-eligible catalog model */
    public AgentEligibleModel $eligibleModel;

    /**
     * @param array<string, mixed> $customerSelectionConfig
     * @param AgentEligibleModel $agentEligibleModel
     * @return static
     */
    public static function forSelectionConfigAndEligibleModel(array $customerSelectionConfig, AgentEligibleModel $agentEligibleModel): static
    {
        $agentCustomerCuratedModel = new static();
        $agentCustomerCuratedModel->customerSelectionConfig = $customerSelectionConfig;
        $agentCustomerCuratedModel->eligibleModel = $agentEligibleModel;
        return $agentCustomerCuratedModel;
    }

    /** @return int The configured `displayOrder`; a model without one sorts last */
    public function getDisplayOrder(): int
    {
        return (int)($this->customerSelectionConfig['displayOrder'] ?? PHP_INT_MAX);
    }
}
