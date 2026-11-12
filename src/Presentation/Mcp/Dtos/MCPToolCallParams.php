<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Dtos;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * JSON-RPC `params` payload for the MCP `tools/call` method.
 *
 * Replaces the bare `?array` on {@see MCPRequestDto::$params}, which the OpenAPI
 * autodocumenter cannot resolve to a named type. Modelled as a ValueObject so the
 * request body is documentable and strongly typed.
 *
 * MCP `tools/call` shape: `{ "name": string, "arguments": object }`.
 */
class MCPToolCallParams extends ValueObject
{
    /** @var string|null The tool to invoke (MCP `tools/call`) */
    public ?string $name = null;

    /**
     * Free-form tool arguments map (tool-specific key/value object).
     * Kept as object|array so arbitrary JSON objects hydrate without a fixed schema;
     * the controller normalises it to an array before dispatching to the tool.
     *
     * @var array|null
     */
    public ?array $arguments = null;
}
