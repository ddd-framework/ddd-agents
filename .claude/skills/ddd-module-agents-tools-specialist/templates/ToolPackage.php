<?php
// placeholder {{NAMESPACE}} sample: AgentsReference\Presentation\Mcp\Demo
// placeholder {{FEATURE}} sample: DemoNotes
// placeholder {{PACKAGE_ID}} sample: demo.notes
// placeholder {{READ_TOOL}} sample: getDemoNotes
// placeholder {{WRITE_TOOL}} sample: addDemoNote

declare(strict_types=1);

namespace {{NAMESPACE}};

use {{NAMESPACE}}\Dtos\{{FEATURE}}AddRequestDto;
use {{NAMESPACE}}\Dtos\{{FEATURE}}GetRequestDto;
use {{NAMESPACE}}\Dtos\{{FEATURE}}McpResponseDto;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;

/**
 * One MCP package = one group of tools. The package is a value object, never a container service: the executor takes
 * it from the container when one is registered, else instantiates it. Bind it to a skill in the host's
 * `config/app/AI/skillPackages.php` (agent path) and/or list it in an MCPBundle (HTTP path).
 *
 * Domain work belongs in a host service the tool method takes as a parameter: the executor injects every parameter
 * that is a public container service, and the schema leaves services out.
 */
class {{FEATURE}}MCPPackage extends MCPPackage
{
    /** @var string|null The package id — unique across the host and the package */
    public static ?string $name = '{{PACKAGE_ID}}';

    /** @var string|null One sentence: what the tools of this package do */
    public static ?string $description = 'Read and add the owner\'s items.';

    /**
     * The owner's items, oldest first. Use it to answer what is stored; it never changes anything.
     *
     * @example {}
     * @example {"limit": 10}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolSummary('The owner\'s items, oldest first — to answer what is stored.')]
    public function {{READ_TOOL}}({{FEATURE}}GetRequestDto $requestDto): {{FEATURE}}McpResponseDto
    {
        $responseDto = new {{FEATURE}}McpResponseDto();
        // Load the rows of the space $requestDto->agentSpaceId through a host service taken as a method parameter;
        // the executor filled agentSpaceId from the conversation when the model left it out.
        $responseDto->items = [];
        $responseDto->totalCount = 0;
        $responseDto->windowSize = $requestDto->limit ?? 0;
        return $responseDto;
    }

    /**
     * Keeps one item in the owner's words and returns all items afterwards. An identical repeat in the same
     * conversation is refused as no new work.
     *
     * @example {"text": "Order coffee beans on Friday"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolSummary('Keep one item for the owner (the owner\'s words); returns all items.')]
    public function {{WRITE_TOOL}}(
        {{FEATURE}}AddRequestDto $requestDto,
        AgentToolCallProvenance $agentToolCallProvenance
    ): {{FEATURE}}McpResponseDto {
        $responseDto = new {{FEATURE}}McpResponseDto();
        // The provenance is filled by the server, never by the model: on the HTTP path its fields are null.
        // A tool that writes a widget hands it to AIConversationMessagesService::appendWidget($agentToolCallProvenance, …).
        // Write through a host service (a method parameter) for the space $requestDto->agentSpaceId.
        $responseDto->items = [trim($requestDto->text)];
        $responseDto->totalCount = 1;
        $responseDto->applied = true;
        return $responseDto;
    }
}
