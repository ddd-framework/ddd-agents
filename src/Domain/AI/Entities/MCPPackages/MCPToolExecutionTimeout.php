<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Raises the wall-clock ceiling for ONE tool's execution above the loop's default
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService}::TOOL_EXECUTION_TIMEOUT_SECONDS, 60 s).
 * The default guards against a stuck external API; a tool whose HONEST duration is a remote job (assemble,
 * submit, poll until it ends: 60–180 s) declares its own ceiling here — otherwise the loop aborts
 * the call in the middle of the job, the job still completes at the remote side, and the model's retry collides
 * with it. Declarative, on the method or the whole package; the loop
 * reads it off the ToolDefinition, no per-tool knowledge in the loop.
 *   #[MCPTool, MCPToolExecutionTimeout(300, 'remote job: assemble, submit, poll until it ends')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPToolExecutionTimeout
{
    /** The loop's ceiling for a tool that declares none. */
    public const int DEFAULT_SECONDS = 60;

    public function __construct(public int $seconds, public string $reason = '')
    {
    }
}
