<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Focused MCP DTO (justified: no REST action reads the reports of a run's own predecessors — the run is the caller,
 * its schedule comes from the call context): how many earlier reports of this schedule to read.
 */
class PreviousTaskRunReportsGetRequestDto extends RequestDto
{
    /** @var int|null How many earlier reports, newest first: 1 (default) to 3. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $count = null;
}
