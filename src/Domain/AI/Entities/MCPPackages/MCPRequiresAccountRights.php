<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Gates an #[MCPTool] method — or a whole MCPPackage class (all its tools) — behind one or more ACCOUNT RIGHTS.
 * The mirror of {@see MCPRequiresFeatureFlags} on the second axis, and the two answer different questions:
 * a feature flag says *the plan of the space includes this capability*, a right says *this USER may use it*.
 *
 * That difference decides what the agent does when the gate closes. A missing flag is purchasable, so the capability
 * becomes an upgrade offer. A missing right is NOT — it is granted by the account administrator, never bought — so a
 * right-locked capability must never render as an upgrade widget; the agent names the right and points at the
 * administrator.
 *
 * The gate is UNION over method ∪ package class, AND within the union, exactly like the flag attribute. It is read at
 * {@see MCPToolEntitlement::createFromReflectionMethod()} and enforced in TWO places that must not diverge: the
 * per-turn catalog build (the schema is absent) and
 * {@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService::dispatchTool()} (the call is refused). Package/method is
 * the AUTHORIZATION level — the executor receives package classes and no skill, and a tool is multi-anchored across
 * skills, so a skill-level declaration alone cannot be enforced at execution. A right declared on a
 * skill MUST also be declared on the packages that skill exposes.
 *
 * The names are constant VALUES of the host's account rights setting — never raw strings,
 * so a typo fails the build instead of silently gating nothing.
 *
 *   #[MCPTool, MCPRequiresAccountRights(AccountRightsSetting::MANAGE_BOOKINGS)]
 *   public function confirmBooking(...): ... {}
 *
 *   #[MCPRequiresAccountRights(AccountRightsSetting::BOOKINGS)]
 *   class WorkshopBookingsMCPPackage extends MCPPackage { ... }
 *
 * ⚠ A right may DEFAULT TO TRUE on the host's rights setting. An account whose stored rights carry no value for
 * such a right therefore reads as GRANTED. Gating on it
 * is deny-only-when-explicitly-revoked; say so at the declaration site so nobody reads it as deny-by-default.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPRequiresAccountRights
{
    /** @var string[] Names of the host's account rights; ALL must be true on the account (AND). */
    public array $accountRightNames;

    public function __construct(string ...$accountRightNames)
    {
        $this->accountRightNames = $accountRightNames;
    }
}
