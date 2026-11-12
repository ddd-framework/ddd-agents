<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * What the agent is doing for the customer right now: the activity lines of the running turn of ONE
 * conversation. The frontend asks while the customer watches, at most once per few seconds.
 */
class AIConversationActivityGetRequestDto extends RequestDto
{
    /** @var int The conversation whose running turn is narrated. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;

    /**
     * @var int|null The message that started the turn the stack follows (the customer's message or the work signal),
     *      pinned by the frontend when the stack starts so a message sent mid-turn does not move the window; empty =
     *      the newest turn.
     */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $turnMessageId = null;
}
