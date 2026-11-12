<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Gates an #[MCPTool] method — or a whole MCPPackage / MCP bundle class (all its tools) — behind one or more product
 * FeatureFlags. The tool is offered to a conversation ONLY if the conversation's agent space has ALL the named flags
 * available. `0` flags = ungated (the default: no attribute). `n` flags on one target = AND (all required).
 *
 * The gate is HIERARCHICAL + UNION: a tool's EFFECTIVE requirement is its own flags ∪ its package's flags ∪ the loading
 * skill's `requiredFeatureFlags` frontmatter ({@see \DDD\Domain\AI\Entities\Skills\AISkill}). Read at
 * {@see MCPTool::createFromReflectionMethod} (method ∪ class) and enforced in the per-turn catalog build — a locked
 * skill/tool is structurally ABSENT from `load_skills` / the tool schemas (it cannot be called). Awareness of a locked
 * capability lives in the product-capabilities context segment as an UPGRADE, never as a callable tool.
 *
 * The flag names are FeatureFlag constant VALUES (e.g. `FeatureFlag::WORKSHOP_BOOKINGS = 'WORKSHOP.BOOKINGS'`).
 *
 *   #[MCPTool, MCPRequiresFeatureFlags(FeatureFlag::WORKSHOP_BOOKINGS_CONFIRM)]
 *   public function confirmBooking(...): ... {}
 *
 *   #[MCPRequiresFeatureFlags(FeatureFlag::WORKSHOP_BOOKINGS)]
 *   class WorkshopBookingsMCPPackage extends MCPPackage { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPRequiresFeatureFlags
{
    /** @var string[] FeatureFlag constant VALUES; ALL must be available on the agent space (AND). */
    public array $featureFlagNames;

    public function __construct(string ...$featureFlagNames)
    {
        $this->featureFlagNames = $featureFlagNames;
    }
}
