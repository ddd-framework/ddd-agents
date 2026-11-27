<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionDriveItemsGetRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $connectionId;
    /** @var string|null The folder to list; omitted = the top of the drive (to pick roots) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $folderId = null;
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $pageToken = null;
}
