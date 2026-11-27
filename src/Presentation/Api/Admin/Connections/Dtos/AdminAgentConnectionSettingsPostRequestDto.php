<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\AgentConnectionScopeBoundaries;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionSettingsPostRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $connectionId;
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $displayName = null;
    /** @var string|null OWNER_ONLY or AGENT_SPACE */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $visibility = null;
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $targetAgentSpaceId = null;
    /** @var AgentConnectionScopeBoundaries|null the boundaries (an empty set = the whole granted account); null = unchanged */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?AgentConnectionScopeBoundaries $scopeBoundaries = null;
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $isDefaultForItsCapabilities = null;
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $paused = null;
}
