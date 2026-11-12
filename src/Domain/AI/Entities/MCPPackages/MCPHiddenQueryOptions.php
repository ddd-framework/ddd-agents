<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;

/**
 * MCP-ONLY doc narrowing, declared on a TOOL METHOD (the `#[MCPTool]` endpoint): hide one or more QueryOptions families
 * (filters / orderBy / expand / select) from THIS tool's AGENT-facing JSON-Schema — without touching the OpenApi/REST
 * schema and without removing the properties.
 *
 * It lives on the METHOD, NOT on the request DTO: the DTO is typically SHARED with the customer/REST endpoint (e.g.
 * a `BookingsGetRequestDto` backs both the client controller AND the agent tool), so narrowing it on the DTO
 * would wrongly strip the frontend's query-options surface too. Putting it on the tool method scopes the narrowing to
 * exactly this agent endpoint.
 *
 * Use it when the MCP tool FORCES those query options server-side, so the model would only be burdened with — or could
 * wrongly fiddle with — knobs it never needs to set. Example: a tool `getBookings` that hard-codes its filter, its
 * `orderBy` and the needed `expand` inside the tool, so the agent
 * must NOT be shown filters/orderBy/expand/select — but the DTO's trait properties stay present, hydrated and writable,
 * so the tool's own assignments still apply (the DEFAULTS stay). Per-tool counterpart of {@see DtoQueryOptions::$expose}
 * — but `expose` narrows BOTH MCP and OpenApi for every consumer of the DTO, whereas THIS is MCP-only AND per-endpoint.
 *
 * Reuses the family constants of {@see DtoQueryOptions} (FILTERS / ORDER_BY / EXPAND / SELECT). Additionally accepts a
 * plain PROPERTY NAME (e.g. 'skip') for the scalar paging params that have no family type — for tools whose backing
 * load does not honor them (a list that pages by date only; a silently ignored skip returns the same page again and
 * misleads the model). Read only by the MCP schema generator ({@see MCPSchema::createFromReflectionMethod}); the
 * OpenApi documenter never reads it.
 *
 *   #[MCPTool]
 *   #[MCPHiddenQueryOptions(DtoQueryOptions::FILTERS, DtoQueryOptions::ORDER_BY, DtoQueryOptions::EXPAND, DtoQueryOptions::SELECT)]
 *   public function getBookings(BookingsGetRequestDto $requestDto, ...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPHiddenQueryOptions
{
    /** @var string[] The QueryOptions families hidden from this tool's MCP/agent doc (DtoQueryOptions::FILTERS … SELECT). */
    public array $hidden;

    public function __construct(string ...$hidden)
    {
        $this->hidden = $hidden;
    }

    /** True if the given QueryOptions family ({@see DtoQueryOptions::FILTERS} …) is hidden from this tool's agent doc. */
    public function hides(string $family): bool
    {
        return in_array($family, $this->hidden, true);
    }
}
