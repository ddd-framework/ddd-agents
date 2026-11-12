<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer of a conversation deletion. */
class AIConversationDeleteResponseDto extends RestResponseDto
{
    /** @var string Response Message */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $message = 'Entity deleted';
}
