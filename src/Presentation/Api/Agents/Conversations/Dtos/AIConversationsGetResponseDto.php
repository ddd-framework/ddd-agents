<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AIConversationsGetResponseDto extends RestResponseDto
{
    /** @var AIConversations Requested AIConversations */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AIConversations $aiConversations;
}