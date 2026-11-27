<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionDriveSearchRequestDto extends RequestDto
{
    /** @var string|null The drive connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string Words of the name or the text */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $query = '';
    /** @var int At most this many hits (1–50) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public int $maximumResults = 20;
}
