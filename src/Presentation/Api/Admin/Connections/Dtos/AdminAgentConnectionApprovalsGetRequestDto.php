<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionApprovalsGetRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::QUERY, required: false)]
    public bool $includeAnswered = false;
}
