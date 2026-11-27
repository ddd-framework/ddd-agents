<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `readConversation`: one page of the dialogue of a conversation, from an index or around one message */
class AgentConversationReadRequestDto extends RequestDto
{
    /** @var int The conversation's id, as searchConversations or searchThisConversation names it (required unless aroundMessageId is given) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public int $conversationId = 0;

    /** @var int|null The id of a message a signal or a link names ("message #<id>"): read the dialogue around it; its conversation is found from it */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $aroundMessageId = null;

    /** @var int|null The first message index to read (default 0; the result names the next page) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $fromIndex = null;

    /** @var int|null The message number of a search hit: read the dialogue around it instead of from fromIndex (default 10 messages) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $aroundIndex = null;

    /** @var int|null The most messages returned (default 30, around a message 10; at most 80) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
