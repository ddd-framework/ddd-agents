<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionFilesSearchRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string Name or full text; empty = newest */
    #[Parameter(in: Parameter::BODY, required: false)]
    public string $query = '';
    /** @var int 1..50 */
    #[Parameter(in: Parameter::BODY, required: false)]
    public int $maximumResults = 20;
}
