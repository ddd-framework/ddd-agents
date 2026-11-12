<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Entitlements;

/**
 * The account-rights axis of an agent entitlement: which named rights the acting account holds. Right names are opaque
 * strings the application defines; null wherever the core holds one means "ungated".
 */
interface AgentAccountRightsInterface
{
    /**
     * The names among `$rightNames` the account does not hold (an unknown name counts as not held), in input order.
     *
     * @param string[] $rightNames
     * @return string[]
     */
    public function getMissingRightNames(array $rightNames): array;
}
