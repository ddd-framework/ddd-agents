<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionApprovalAnswerPostRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $approvalRequestId;
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $option = '';
}
