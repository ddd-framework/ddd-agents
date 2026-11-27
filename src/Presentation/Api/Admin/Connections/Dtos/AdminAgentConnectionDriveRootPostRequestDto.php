<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionDriveRootPostRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $connectionId;
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $itemId = '';
    /** @var string PERSON_ROOT (picked on an own connection) or PICKER (a Picker-only connection) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public string $releaseWay = 'PERSON_ROOT';
}
