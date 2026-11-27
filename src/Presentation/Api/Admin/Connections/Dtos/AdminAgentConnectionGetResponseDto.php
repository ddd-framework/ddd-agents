<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLinks;
use DDD\Domain\AI\Entities\Connections\AgentConnectionActions;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddresses;
use DDD\Domain\AI\Entities\Connections\AgentConnectionLevelRequest;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermissions;
use DDD\Domain\AI\Entities\Connections\AgentConnectionReleases;
use DDD\Presentation\Mcp\Client\Connections\Dtos\AgentConnectionSummaryDto;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionGetResponseDto extends RestResponseDto
{
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConnectionSummaryDto $connection;

    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConnectionPermissions $permissions;

    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConnectionReleases $releases;

    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConnectionAddresses $addresses;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionAccountLinks $accountLinks = null;

    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConnectionActions $actions;

    /** @var string[] The scopes the provider granted */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $grantedScopes = [];

    /** @var AgentConnectionLevelRequest[] per capability the highest level the provider's grant covers; a higher level needs a consent first (OAuth start with connectionId) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $grantedLevels = [];
}
