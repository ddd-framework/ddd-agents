<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionCommentsRequestDto extends RequestDto
{
    /** @var string|null The drive connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string The item's id (a document, spreadsheet, presentation, file or folder) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $itemId = '';
    /** @var bool Also resolved comments */
    #[Parameter(in: Parameter::BODY, required: false)]
    public bool $includeResolved = false;
}
