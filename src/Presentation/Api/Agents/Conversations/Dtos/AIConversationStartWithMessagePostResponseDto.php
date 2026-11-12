<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The conversation a start returned: the new one with its first message, or — a start with an `entryPointKey` — the
 * recent conversation that entry point opened, without its messages (the client loads them through the messages
 * endpoint) and without a new turn.
 */
class AIConversationStartWithMessagePostResponseDto extends AIConversationGetResponseDto
{
    /** @var bool True when the start reopened the recent conversation of its entry point: nothing was appended and no turn started. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $reopenedExistingConversation = false;
}
