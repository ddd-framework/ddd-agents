<?php
// placeholder {{NAMESPACE}} sample: AgentsReference\Presentation\Mcp\Demo
// placeholder {{FEATURE}} sample: DemoNotes
// placeholder {{ROUTE_SEGMENT}} sample: /demo-notes

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;
use DDD\Presentation\Base\Router\Routes\Route;
use DDD\Presentation\Mcp\Dtos\MCPRequestDto;
use DDD\Presentation\Mcp\Dtos\MCPResponseDto;
use DDD\Presentation\Mcp\MCPController;
use Symfony\Component\HttpFoundation\Request;

/**
 * The HTTP MCP mount of the bundle: JSON-RPC (initialize, tools/list, tools/call) as one SSE frame. The dispatch
 * lives in MCPController; this class only declares the route, the Tag (name and purpose on initialize) and the
 * bundle. The host's firewall must cover the route: initialize and tools/list check no account of their own.
 */
#[Route('{{ROUTE_SEGMENT}}')]
#[Tag(name: '{{FEATURE}} MCP', group: 'MCP', description: 'Read and add the owner\'s items.')]
class {{FEATURE}}MCPController extends MCPController
{
    public const ?string BUNDLE = {{FEATURE}}MCPBundle::class;

    #[Post]
    #[Summary('MCP Endpoint')]
    public function mcp(MCPRequestDto $requestDto, Request $request): MCPResponseDto
    {
        return $this->handleMcpRequest($requestDto, $request);
    }

    /**
     * The tool list and schemas as static JSON. `?noCache=1` refreshes only this action's own cache entry; after a
     * schema change call MCPBundle::clearDocumentationCache() so that tools/list is rebuilt too.
     */
    #[Get('/documentation')]
    #[Summary('MCP Documentation')]
    public function documentation(Request $request): MCPResponseDto
    {
        return $this->buildDocumentationResponse($request->query->getBoolean('noCache'));
    }
}
