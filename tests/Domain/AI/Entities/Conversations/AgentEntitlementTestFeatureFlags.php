<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;

/** A flag set of fixed names (test double of the application's applied feature flags). */
class AgentEntitlementTestFeatureFlags implements AgentFeatureFlagsInterface
{
    /** @param string[] $availableFeatureFlagNames */
    public function __construct(protected array $availableFeatureFlagNames)
    {
    }

    public function hasAllAvailableFeatureFlags(array $requiredFeatureFlagNames): bool
    {
        return array_diff($requiredFeatureFlagNames, $this->availableFeatureFlagNames) === [];
    }

    public function hasAnyAvailableFeatureFlag(array $anyOfFeatureFlagNames): bool
    {
        return array_intersect($anyOfFeatureFlagNames, $this->availableFeatureFlagNames) !== [];
    }
}
