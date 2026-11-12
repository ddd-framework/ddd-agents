<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Retry a FAILED run of an existing AIConversation: the persisted, still-unanswered customer message is re-driven — the
 * customer does not send it again.
 */
class AIConversationRetryPostRequestDto extends RequestDto
{
    /** @var int The id of the AIConversation whose FAILED run to retry. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;
}
