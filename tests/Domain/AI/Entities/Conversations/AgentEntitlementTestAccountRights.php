<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;

/** A rights set of fixed granted names (test double of the application's account rights). */
class AgentEntitlementTestAccountRights implements AgentAccountRightsInterface
{
    /** @param string[] $grantedRightNames */
    public function __construct(protected array $grantedRightNames)
    {
    }

    public function getMissingRightNames(array $rightNames): array
    {
        return array_values(array_diff($rightNames, $this->grantedRightNames));
    }
}
