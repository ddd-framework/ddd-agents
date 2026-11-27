<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionContactMergeRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string The person that stays (My Contacts) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $survivorContactId = '';
    /** @var string The person merged into it and deleted afterwards (My Contacts) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $donorContactId = '';
}
