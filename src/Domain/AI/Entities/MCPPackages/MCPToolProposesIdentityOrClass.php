<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks a tool whose call proposes or sets WHO the space stands for (its identity, a record imported for it) or WHAT
 * CLASS it is (the kind of thing it is, how it works). A call of such a tool in the previous assistant turn makes the
 * owner's short reply an identity reply, which the reasoning-effort decision admits to its questions
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentReasoningEffortDecisionsService}).
 *
 * `resultIsQuotedAsIdentityResolution`: the newest non-empty result of this tool is quoted to the same decision as the
 * stored fact "identity resolution: …".
 *
 *   #[MCPTool]
 *   #[MCPToolProposesIdentityOrClass]
 *   public function updateWorkshopKind(...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolProposesIdentityOrClass
{
    public function __construct(public bool $resultIsQuotedAsIdentityResolution = false)
    {
    }
}
