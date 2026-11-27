<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionMailSearchRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string Provider search syntax (Gmail: from:x newer_than:7d is:unread; IMAP: plain text); omitted = the newest mails of the inbox */
    #[Parameter(in: Parameter::BODY, required: false)]
    public string $query = '';
    /** @var int 1..50, default 10 */
    #[Parameter(in: Parameter::BODY, required: false)]
    public int $maximumResults = 10;
}
