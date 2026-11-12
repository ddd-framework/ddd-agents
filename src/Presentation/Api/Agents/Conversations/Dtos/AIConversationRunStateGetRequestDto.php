<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The run-state read of one conversation — the cheap metadata read a polling client repeats while a turn runs.
 */
class AIConversationRunStateGetRequestDto extends RequestDto
{
    /** @var int The id of the AIConversation. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;
}
