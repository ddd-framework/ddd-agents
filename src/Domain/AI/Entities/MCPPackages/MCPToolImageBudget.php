<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Raises the number of IMAGE parts ONE tool's result may carry above the executor's default
 * ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService::DEFAULT_MAX_IMAGES_PER_TOOL_CALL}, 4), up to the hard ceiling
 * ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService::MAX_IMAGES_PER_TOOL_CALL_CEILING}). The default guards
 * against a tool flooding one result with pictures; a tool whose HONEST output is several composed sheets (the layouts
 * of four section types, two cells wide, six per sheet) declares its own budget here — excess images still degrade to
 * a text note, never a failed call. Declarative, on the method or the whole package; the executor reads it off the
 * ToolDefinition, no per-tool knowledge in the executor.
 *   #[MCPTool, MCPToolImageBudget(8, 'one 2-column sheet per requested section type, ≤ 6 layouts each')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPToolImageBudget
{
    public function __construct(public int $images, public string $reason = '')
    {
    }
}
