<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundaries;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCapabilityCeilings;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionReleasePostRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $connectionId;
    #[Parameter(in: Parameter::BODY, required: true)]
    public int $agentSpaceId;
    /** @var AgentConnectionCapabilityCeilings|null the ceiling per capability; null = every capability, at most READ */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?AgentConnectionCapabilityCeilings $capabilityCeilings = null;
    /** @var AgentConnectionScopeBoundaries|null boundaries for this space; null = unchanged */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?AgentConnectionScopeBoundaries $scopeBoundaries = null;
}
