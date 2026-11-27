<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionAccountLinkPostRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $connectionId;
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $externalUserId = '';
    #[Parameter(in: Parameter::BODY, required: true)]
    public int $accountId;
}
