<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequests;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionApprovalsGetResponseDto extends RestResponseDto
{
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentConnectionApprovalRequests $approvalRequests;
}
