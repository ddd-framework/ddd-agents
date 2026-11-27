<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `readConversation`: one page of the dialogue of an earlier conversation */
class AgentConversationReadRequestDto extends RequestDto
{
    /** @var int The conversation's id, as searchConversations names it (required) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public int $conversationId = 0;

    /** @var int|null The first message index to read (default 0; the result names the next page) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $fromIndex = null;

    /** @var int|null The most messages returned (default 30, at most 80) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
