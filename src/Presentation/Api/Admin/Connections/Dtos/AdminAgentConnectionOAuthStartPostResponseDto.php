<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionOAuthStartPostResponseDto extends RestResponseDto
{
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $connectionId = 0;

    /** @var string The consent screen the browser opens */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $authorizationUrl = '';

    /** @var string The redirect URI that must be registered at the provider */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $redirectUri = '';
}
