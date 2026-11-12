<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;

/**
 * An MCP package that builds the owner-consent request of its `#[MCPToolRequiresOwnerConfirmation]` tool from what it
 * LOADS for the call's arguments (the entities the arguments name), never from the model's prose. The
 * tools check the consent in their own body; the host's owner-consent replay command still asks the packages for the
 * historical calls' requests. Null when the arguments name no loadable target.
 */
interface OwnerConsentRequestProvider
{
    /** @param array<array-key, mixed> $arguments the call's arguments after the executor's agent-space defaults */
    public function buildOwnerConsentRequest(string $toolName, array $arguments, AgentToolCallProvenance $callProvenance): ?OwnerConsentRequest;
}
