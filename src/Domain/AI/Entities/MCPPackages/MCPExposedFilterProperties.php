<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * The FILTER and SORT properties a list tool offers the agent, declared on the TOOL METHOD (the `#[MCPTool]`
 * endpoint) whose request DTO carries QueryOptions.
 *
 * The framework derives the filterable properties from the base entity — every scalar column, internal ones
 * included (the conversation set yields 35: `runToken`, `resumeAttempts`, `episodicHistory`, …). A tool names the few
 * that answer an owner's question:
 *
 *   #[MCPTool]
 *   #[MCPExposedFilterProperties('created', 'scheduleId', 'runState')]
 *   public function getTaskRuns(TaskRunsGetRequestDto $requestDto, ...): ... {}
 *
 * Two readers, one list:
 *  - the MCP schema generator ({@see MCPSchema::createFromReflectionMethod}) documents ONLY these properties under
 *    the tool's `filters` parameter (each with its allowed values where the entity declares them);
 *  - the executor ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService}) refuses a call whose `filters` or
 *    `orderBy` names any other property, with the allowed names in the message — hiding a property from the schema
 *    alone would leave it usable for a model that guesses the name.
 *
 * MCP-only and per-endpoint, like {@see MCPHiddenQueryOptions}: the request DTO and the REST surface keep the full
 * set. The mandatory scope a tool AND-connects itself (agent space, archive hide, …) is server code and unaffected.
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPExposedFilterProperties
{
    /** @var string[] The property names the agent may filter and sort on. */
    public array $propertyNames;

    public function __construct(string ...$propertyNames)
    {
        $this->propertyNames = array_values($propertyNames);
    }

    /** True if the agent may filter or sort on the given property (`id` is always sortable — the stable tie-breaker). */
    public function exposes(string $propertyName): bool
    {
        return $propertyName === 'id' || in_array($propertyName, $this->propertyNames, true);
    }
}
