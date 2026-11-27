<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `searchConversations`: the owner's earlier conversations in every space they may read */
class AgentConversationsSearchRequestDto extends RequestDto
{
    /** @var string|null What was talked about: names, terms, a topic (titles and messages are searched). Omit it for the most recent conversations */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $query = null;

    /** @var int|null Only conversations of this space: the number of its label in a result (42 of space:42); omit it to search every space */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $onlySpaceId = null;

    /** @var string|null Only conversations active on or after this day (YYYY-MM-DD) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $fromDate = null;

    /** @var string|null Only conversations started on or before this day (YYYY-MM-DD) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $toDate = null;

    /** @var int|null The most conversations returned (default 6, at most 15) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
