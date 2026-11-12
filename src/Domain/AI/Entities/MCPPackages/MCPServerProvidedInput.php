<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

/**
 * Marker for a tool-method parameter that the SERVER provides at execution time — NOT a model-facing input.
 *
 * A fourth input category alongside RequestDto / ValueObject / Entity: a type implementing this is
 *  - EXCLUDED from the generated tool JSON-Schema ({@see MCPSchema::createFromReflectionMethod}) — the model never
 *    sees it and is never burdened with (or able to forge) its value, exactly like an injected Service; and
 *  - INJECTED by the executor ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService::buildMethodArguments}) from
 *    the value threaded into `callTool()` (an empty instance on the HTTP-MCP path, which provides nothing).
 *
 * Use it for context the agent loop knows but the model must not supply — e.g. the provenance of the running tool
 * call ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance}: the user message that prompted
 * it + the assistant message that emitted it). Future server-side inputs (taint/provenance for the security layer,
 * the owner's original wording) implement this same interface.
 */
interface MCPServerProvidedInput
{
}
