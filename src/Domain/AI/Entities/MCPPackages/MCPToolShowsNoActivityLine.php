<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method whose calls the activity display never shows: no line while it runs and none afterwards
 * ({@see \DDD\Domain\AI\Services\Conversations\AgentActivityLinesService::buildElement()}). For a tool whose call is
 * bookkeeping the owner did not ask to watch (storing a memory). The loop's own tools are silent without it.
 *
 * Read by tool name through {@see MCPToolAttributeCatalog}, which reads the method of the class an application binds:
 * an application that declares the tool on its own package class repeats the attribute there.
 *
 *   #[MCPTool, MCPToolShowsNoActivityLine('bookkeeping, not work the owner watches')]
 *   public function storeMemory(StoreMemoryRequestDto $requestDto): StoreMemoryResponseDto {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolShowsNoActivityLine
{
    public function __construct(public string $reason = '')
    {
    }
}
