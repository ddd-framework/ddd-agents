<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Gates an #[MCPTool] method — or a whole MCPPackage class (all its tools) — behind ANY ONE of the named product
 * FeatureFlags (OR semantics). The tool is offered ONLY if the conversation's agent space has AT LEAST ONE of the
 * named flags available. Composes with {@see MCPRequiresFeatureFlags} (AND): the effective gate is
 * `ALL(required) AND ANY(anyOf)`.
 *
 * Exists for a capability fulfilled by ALTERNATIVE product flags — two integrations that each satisfy the same
 * feature — an AND gate would wrongly lock out customers entitled via only one flag, and gating on a single flag
 * would wrongly upsell the customers entitled via the other. In the product awareness a
 * tool locked by an any-of gate renders under EVERY unlocking flag's target plan.
 *
 *   #[MCPTool, MCPRequiresAnyFeatureFlag(FeatureFlag::SOME_CAPABILITY_VIA_A, FeatureFlag::SOME_CAPABILITY_VIA_B)]
 *   public function someTool(...): ... {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPRequiresAnyFeatureFlag
{
    /** @var string[] FeatureFlag constant VALUES; AT LEAST ONE must be available on the agent space (OR). */
    public array $featureFlagNames;

    public function __construct(string ...$featureFlagNames)
    {
        $this->featureFlagNames = $featureFlagNames;
    }
}
