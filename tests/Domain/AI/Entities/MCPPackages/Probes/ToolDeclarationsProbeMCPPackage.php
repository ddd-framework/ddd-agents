<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReceivesCurrentConversationId;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResultIsNeverStubbedByTheBudget;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSendsMessageToOwner;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolShowsNoActivityLine;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolWritesAgentMemoryOnly;

/**
 * A package whose tools each declare one of the properties the loop reads by tool name, and one tool that declares
 * none. No tool does any work: the tests ask the loop what it reads from the declaration.
 */
class ToolDeclarationsProbeMCPPackage extends MCPPackage
{
    #[MCPTool]
    #[MCPToolReceivesCurrentConversationId('reads the conversation that calls it')]
    public function probeSearchOfThisConversation(): string
    {
        return '';
    }

    #[MCPTool]
    #[MCPToolWritesAgentMemoryOnly('stores one memory row')]
    #[MCPToolShowsNoActivityLine('bookkeeping')]
    public function probeStoreMemory(): string
    {
        return '';
    }

    #[MCPTool]
    #[MCPToolResultIsNeverStubbedByTheBudget('an answer is composed from this page')]
    public function probePageRead(): string
    {
        return '';
    }

    #[MCPTool]
    #[MCPToolSendsMessageToOwner('sends the owner an email')]
    public function probeEmailToOwner(): string
    {
        return '';
    }

    #[MCPTool]
    public function probeRecordChange(): string
    {
        return '';
    }
}
