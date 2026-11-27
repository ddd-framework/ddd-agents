<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionsListMcpResponseDto extends RestResponseDto
{
    /** @var AgentConnectionSummaryDto[] Every connection usable here, of every provider and mode */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $connections = [];
}
