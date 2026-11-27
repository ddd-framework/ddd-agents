<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `searchThisConversation`: what to look for earlier in the conversation the call runs in */
class AgentConversationSearchThisRequestDto extends RequestDto
{
    /** @var string What was said: a topic, a name, a detail (required) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $query = '';

    /** @var int|null The most messages returned (default 5, at most 10) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
