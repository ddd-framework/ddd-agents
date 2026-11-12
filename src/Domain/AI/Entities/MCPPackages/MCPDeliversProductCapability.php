<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Declares which product capabilities an #[MCPTool] method — or a whole MCPPackage class (all its tools) — delivers
 * in the chat: the keys of the application's capability tree (e.g.
 * `Workshop.Bookings.ConfirmABooking`). The dependency runs AI → Product: the capability knows no tool, the tool
 * names the capability. An application's coverage check can read these declarations against what its capability
 * tree says the agent covers, and report a capability with a tool but no coverage, or the reverse. Informational
 * for the loop (never a gate).
 *
 *   #[MCPTool, MCPDeliversProductCapability('Workshop.Bookings.ConfirmABooking')]
 *   public function confirmBooking(...): ... {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPDeliversProductCapability
{
    /** @var string[] Capability keys of the product capability tree. */
    public array $productCapabilityKeys;

    public function __construct(string ...$productCapabilityKeys)
    {
        $this->productCapabilityKeys = $productCapabilityKeys;
    }
}
