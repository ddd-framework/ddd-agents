<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method as a consequential, outward-facing action the agent loop may only execute under an
 * ACTIVE {@see \DDD\Domain\AI\Entities\Mandates\AgentMandate} covering the declared action class.
 * A mandate is granted from the CUSTOMER's
 * own statement in the conversation, verified server-side against the transcript
 * ({@see \DDD\Domain\AI\Services\Mandates\AgentMandatesService::grantMandateFromDialogue()}) — never from a
 * model-supplied claim.
 *
 * Relationship to {@see MCPToolRequiresOwnerConfirmation}: the owner's consent authorizes ONE irreversible
 * instance from the owner's own explicit agreement; the mandate gate authorizes a CLASS of recurring outward actions via the customer's
 * statement — once, for a time window, or until revoked. A tool may carry both (the mandate says "you may reply to
 * reviews", the confirmation still guards a specifically destructive instance).
 *
 * Enforcement lives in {@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService::refuseActionLackingMandate()}:
 * in the AGENT LOOP (a call carrying provenance) the executor looks up the newest ACTIVE, time-valid mandate for
 * (account, agent space, action class). Without one (or with a ONE_INSTRUCTION of an earlier request) the executor
 * checks the customer's consent ITSELF against the live dialogue and grants a ONE_INSTRUCTION when
 * the current request carries it; otherwise the call is refused and the model asks the customer. The model calls
 * `grantMandate` only for a standing permission. The no-provenance paths (HTTP-MCP server — the authenticated owner IS the
 * consent-giver — and executor-direct eval/admin calls) are deliberately ungated, mirroring the confirmation gate.
 *
 *   #[MCPTool, MCPToolRequiresMandate(AgentMandate::ACTION_STANDING_AUTOMATION)]
 *   public function createSchedule(...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolRequiresMandate
{
    /**
     * The seconds the executor's own consent check may take before the tool runs (one approval model call of up to
     * 45 s, run twice without a verdict) — added to the tool's execution ceiling ({@see MCPTool}).
     */
    public const int CONSENT_CHECK_SECONDS = 100;

    /** A tool ceiling from which the consent check is not added (the tool already allows long runs; the reaper reads a
     * run without a heartbeat for 300 s as hung). */
    public const int LONG_CEILING_SECONDS = 200;

    /**
     * @param string $actionClass One of {@see \DDD\Domain\AI\Entities\Mandates\AgentMandate::getActionClasses()}
     * @param string|null $bindingKey The name a ONE_INSTRUCTION mandate binds to on first use — by default the tool's
     * own name (deletePost consent ≠ publishPost). Tools that are the SAME customer intent through different
     * mechanics (publishReplySuggestion and replyToReview both publish ONE reply) share a key, so a grant for
     * "send the reply" rides whichever of them the agent reaches for.
     */
    public function __construct(public string $actionClass, public ?string $bindingKey = null)
    {
    }

    /** The name the one-instruction binding uses for a tool: the declared key, else the tool's own name. */
    public function bindingKeyForTool(string $toolName): string
    {
        return $this->bindingKey ?? $toolName;
    }
}
