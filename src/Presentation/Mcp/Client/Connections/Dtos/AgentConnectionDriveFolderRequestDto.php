<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionDriveFolderRequestDto extends RequestDto
{
    /** @var string|null The drive connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string|null A folder inside the released roots; omitted = the roots themselves */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $folderId = null;
    /** @var string|null The nextPageToken of the previous page */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $pageToken = null;
}
