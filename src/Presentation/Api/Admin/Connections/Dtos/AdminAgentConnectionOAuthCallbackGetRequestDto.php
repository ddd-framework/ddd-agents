<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionOAuthCallbackGetRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $provider = '';
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $code = null;
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $state = null;
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $error = null;
}
