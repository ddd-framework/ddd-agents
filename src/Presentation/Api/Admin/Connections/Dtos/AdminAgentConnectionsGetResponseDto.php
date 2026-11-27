<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionSummaryDto;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionsGetResponseDto extends RestResponseDto
{
    /** @var AgentConnectionSummaryDto[] */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $connections = [];

    /** @var string[] The providers that can be connected here (switched on and configured) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $connectableProviders = [];

    /** @var bool Whether the account may manage the agent's system connections */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $mayManageSystemConnections = false;

    /** @var int[] The spaces the account may share a connection with */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $shareableAgentSpaceIds = [];
}
