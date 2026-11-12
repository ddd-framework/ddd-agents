<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Entitlements;

/**
 * The feature-flag axis of an agent entitlement: which named capabilities the scope (the conversation's agent space) has.
 * The flag names are opaque strings only the application interprets (legacy names, groups); the core only asks the two
 * predicates. An application's flag set implements it; null wherever the core holds one means "ungated".
 */
interface AgentFeatureFlagsInterface
{
    /**
     * True when every named flag is available to the scope.
     *
     * @param string[] $requiredFeatureFlagNames
     * @return bool
     */
    public function hasAllAvailableFeatureFlags(array $requiredFeatureFlagNames): bool;

    /**
     * True when at least one named flag is available to the scope.
     *
     * @param string[] $anyOfFeatureFlagNames
     * @return bool
     */
    public function hasAnyAvailableFeatureFlag(array $anyOfFeatureFlagNames): bool;
}
