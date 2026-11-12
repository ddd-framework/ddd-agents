<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp;

use DDD\Domain\AI\Entities\MCPPackages\MCPBundle;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsSyntax;
use DDD\Presentation\Base\Dtos\SharedRequestParametersSyntax;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Dtos\JsonRpcError;
use DDD\Presentation\Mcp\Dtos\JsonRpcErrorDto;
use DDD\Presentation\Mcp\Dtos\JsonRpcResponseDto;
use DDD\Presentation\Mcp\Dtos\McpCapabilitiesResponseDto;
use DDD\Presentation\Mcp\Dtos\MCPRequestDto;
use DDD\Presentation\Mcp\Dtos\MCPResponseDto;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Reflection\ReflectionClass as DDDReflectionClass;
use DDD\Presentation\Base\Controller\HttpController;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Symfony\Security\Authenticators\TokenAuthenticator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base MCP Controller for handling MCP package tools
 * Each domain controller extends this and provides its package classes
 * Uses MCPBundle to provide deduplicated documentation
 */
abstract class MCPController extends HttpController
{
    public const ?string BUNDLE = null;

    /**
     * Single source of truth for the packages handled by this controller: the bundle's
     * INCLUDED_PACKAGES. Both tools/list (documentation + cache) and tools/call dispatch
     * resolve from here, so the listed/cached surface and the callable surface cannot diverge.
     */
    protected function getPackageClasses(): array
    {
        $bundle = static::BUNDLE;
        return $bundle ? $bundle::INCLUDED_PACKAGES : [];
    }

    /**
     * Handles a JSON-RPC MCP request (initialize / tools/list / tools/call / notifications).
     * Concrete controllers only declare the route, the #[Tag] and the BUNDLE constant, then delegate here.
     */
    protected function handleMcpRequest(MCPRequestDto $requestDto, Request $request): MCPResponseDto
    {
        try {
            switch ($requestDto->method) {
                case 'initialize':
                    $reflectionClass = DDDReflectionClass::instance(static::class);
                    /** @var Tag $tagAttributeInstance */
                    $tagAttributeInstance = $reflectionClass->getAttributeInstance(Tag::class);
                    $name = '';
                    $instructions = '';
                    if ($tagAttributeInstance) {
                        $name = $tagAttributeInstance->name;
                        $instructions = 'Use for the following purpose: ' . $tagAttributeInstance->description;
                    }
                    // the audience's own lines come right after the purpose: a client may shorten long instructions
                    $instructions .= $this->buildAdditionalInitializeInstructions();
                    // Emit the shared QueryOptions grammar + shared-request-parameters docs ONCE per server connect (the
                    // per-tool parameter descriptions carry only their endpoint-specific list / a short pointer). Only
                    // when this bundle actually exposes them; computed via the same flags the agent loop uses. initialize
                    // is infrequent, so building the tool catalog here once is fine.
                    $bundleToolDefinitions = ToolDefinitions::forPackages($this->getPackageClasses());
                    if ($bundleToolDefinitions->usesQueryOptions) {
                        $instructions .= "\n\n" . QueryOptionsSyntax::getSyntaxDocumentation();
                    }
                    if ($bundleToolDefinitions->usesSharedParams) {
                        $instructions .= "\n\n" . SharedRequestParametersSyntax::getDocumentation();
                    }
                    $capabilitiesResponseDto = new McpCapabilitiesResponseDto($name, $instructions);
                    foreach ($this->buildAdditionalCapabilities() as $capabilityName => $capability) {
                        $capabilitiesResponseDto->capabilities->{$capabilityName} = $capability;
                    }
                    $jsonRcpResponse = new JsonRpcResponseDto($requestDto->id ?? 0, $capabilitiesResponseDto);
                    return new MCPResponseDto(restResponseDto: $jsonRcpResponse);

                case 'ping':
                case 'logging/setLevel':
                case 'notifications/initialized':
                    $jsonRcpResponse = new JsonRpcResponseDto($requestDto->id ?? 0);
                    return new MCPResponseDto(restResponseDto: $jsonRcpResponse);

                case 'tools/list':
                    $jsonRcpResponse = new JsonRpcResponseDto($requestDto->id ?? 0, $this->buildToolsListDocumentation());
                    Entity::addStaticPropertiesToHide(false, 'objectType');
                    return new MCPResponseDto(restResponseDto: $jsonRcpResponse);

                case 'tools/call':
                    $toolName = $requestDto->params?->name;
                    if (!$toolName) {
                        throw new JsonRpcError(JsonRpcError::JSON_RPC_CODE_INVALID_PARAMS, 'Missing required parameter: name');
                    }
                    $arguments = $requestDto->params?->arguments ?? [];

                    $authAccount = AuthService::instance()->getAccount();
                    // Redundant to the `^/app/mcp/` firewall (ROLE_USER), but fail closed:
                    // never execute a tool without a resolved account.
                    if (!$authAccount) {
                        throw new UnauthorizedException('Authentication required for MCP tool calls');
                    }
                    $accessToken = null;
                    if ($authorizationHeader = $request->headers->get('Authorization')) {
                        if (str_starts_with($authorizationHeader, TokenAuthenticator::TOKEN_BEARER)) {
                            $accessToken = str_replace(TokenAuthenticator::TOKEN_BEARER . ' ', '', $authorizationHeader);
                        }
                    }
                    if (!$accessToken) {
                        // Mint an access token for the resolved account so the internal tool call is authenticated.
                        $refreshToken = AuthService::instance()->getRefreshTokenForAccountId(
                            $authAccount->id,
                            isShortLived: true
                        );
                        $accessToken = AuthService::instance()->getAccessTokenForAccountBasedOnRefreshToken(
                            $refreshToken
                        );
                    }

                    // Bridge JSON-RPC "tools/call" to the internal tool executor
                    $symfonyRequest = new Request([], [
                        'tool' => $toolName,
                    ]);
                    if ($accessToken) {
                        $symfonyRequest->headers->set('Authorization', 'Bearer ' . $accessToken);
                    }
                    /** @var MCPToolExecutorService $executor */
                    $executor = DDDService::instance()->getService(MCPToolExecutorService::class);
                    $mcpToolResponse = $executor->callTool(
                        $toolName,
                        $arguments,
                        $this->getPackageClasses(),
                        $symfonyRequest,
                        $this->mayCallerReadRawToolErrors($request)
                    );
                    $mcpToolResponse = $this->shapeToolResponseForAudience($toolName, $mcpToolResponse);
                    $jsonRcpResponse = new JsonRpcResponseDto($requestDto->id ?? 0, (object)$mcpToolResponse->jsonSerialize());
                    return new MCPResponseDto(restResponseDto: $jsonRcpResponse);

                default:
                    throw new JsonRpcError(JsonRpcError::JSON_RPC_CODE_METHOD_NOT_FOUND, 'Unknown or missing request method: ' . ($requestDto->method ?? 'unknown'));
            }
        } catch (JsonRpcError $jsonRpcError) {
            $jsonRcpResponse = new JsonRpcResponseDto(
                id: $requestDto->id ?? 0,
                result: null,
                error: JsonRpcErrorDto::fromError($jsonRpcError)
            );
            return new MCPResponseDto(restResponseDto: $jsonRcpResponse);
        }
    }

    /**
     * Override point: whether a tool call of this request may answer with the tool's raw exception instead of the
     * failure text (the `errors_as_json` query parameter, a debug aid: the exception and its stack leave the process).
     * Only an admin with access to all resources may ask for it; for every other caller the parameter has no effect.
     *
     * @param Request $request
     * @return bool
     */
    protected function mayCallerReadRawToolErrors(Request $request): bool
    {
        if (!$request->query->has('errors_as_json')) {
            return false;
        }
        return AuthService::instance()->getAccount()?->isAdminWithAccessToAllResources() === true;
    }

    /**
     * The `tools/list` answer: the bundle's documentation (cached per composition). An audience that serves a subset per
     * caller overrides it (the admin audience's single mount lists only the granted bundles' tools).
     */
    protected function buildToolsListDocumentation(): object
    {
        /** @var class-string<MCPBundle> $bundle */
        $bundle = static::BUNDLE;
        return $bundle::buildDocumentation(includeOutputSchema: false, addObjectsToDef: true, maxDepth: 6);
    }

    /**
     * The last shaping of a tool result before it goes on the wire — the admin audience cuts an oversized result.
     * Unchanged by default. Typed `object` (the executor's tool response) so this core file names
     * no further Presentation class.
     */
    protected function shapeToolResponseForAudience(string $toolName, object $mcpToolResponse): object
    {
        return $mcpToolResponse;
    }

    /**
     * Capabilities an audience declares beside `tools` on `initialize` (the admin audience: `prompts`),
     * keyed by capability name. None by default.
     *
     * @return array<string, object>
     */
    protected function buildAdditionalCapabilities(): array
    {
        return [];
    }

    /**
     * Text an audience appends to the `initialize` instructions (the admin audience: its guide index).
     * Empty by default; a non-empty text starts with its own blank line.
     */
    protected function buildAdditionalInitializeInstructions(): string
    {
        return '';
    }

    /**
     * List all tools from packages in this controller
     * Uses MCPBundle to provide deduplicated documentation
     */
    protected function listTools(bool $noCache = false): MCPResponseDto
    {
        if (!static::BUNDLE) {
            return new MCPResponseDto();
        }
        /** @var MCPBundle $bundle */
        $bundle = static::BUNDLE;
        return new MCPResponseDto(json: $bundle::buildDocumentation(noCache: $noCache)->toJSON());
    }

    /**
     * Plain-HTTP documentation of this controller's bundle — the same tool list + JSON-Schemas as the JSON-RPC
     * `tools/list`, but returned as static JSON (SSE off) over a simple GET. Each concrete MCP controller exposes
     * it via a `#[Get('/documentation')]` action that delegates here. `$noCache` forces a rebuild (cache refresh).
     */
    protected function buildDocumentationResponse(bool $noCache = false): MCPResponseDto
    {
        return $this->listTools($noCache)->setServerSentEvents(false);
    }
}