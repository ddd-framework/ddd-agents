<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method whose only writes are agent memory rows (it stores, changes or deletes a memory and
 * touches no other row). Two readers ask for it by tool name ({@see MCPToolAttributeCatalog}):
 *
 * - the admission of parallel calls: such a call runs inline beside forked calls without making them wait, because it
 *   cannot write a row a forked call reads
 *   ({@see \DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService::responseHasInlineWriteBesideTheFork()});
 * - the standing directives of the system prompt: they are read again after a completed call of such a tool and not
 *   before ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::findMemoryDirectivesBlock()}).
 *
 * A tool that also writes anything else must not carry it. The catalog reads the method of the class an application
 * binds: an application that declares the tool on its own package class repeats the attribute there.
 *
 *   #[MCPTool, MCPToolWritesAgentMemoryOnly('stores one memory row')]
 *   public function storeMemory(StoreMemoryRequestDto $requestDto): StoreMemoryResponseDto {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolWritesAgentMemoryOnly
{
    public function __construct(public string $reason = '')
    {
    }
}
