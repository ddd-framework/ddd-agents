<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItems;
use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoots;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionDriveRootsGetResponseDto extends RestResponseDto
{
    /** @var AgentConnectionDriveRoots The roots (ACTIVE, UNASSIGNED) with the way each came */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConnectionDriveRoots $driveRoots;

    /** @var AgentConnectionDriveItems|null The items of a folder (picking roots) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionDriveItems $driveItems = null;

    /** @var string|null What happened */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;
}
