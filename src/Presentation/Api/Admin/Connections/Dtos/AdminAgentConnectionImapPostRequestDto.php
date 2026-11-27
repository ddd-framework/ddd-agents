<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionImapPostRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $displayName = null;
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $fromAddress = '';
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $username = '';
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $password = '';
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $imapHost = '';
    #[Parameter(in: Parameter::BODY, required: false)]
    public int $imapPort = 993;
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $smtpHost = '';
    #[Parameter(in: Parameter::BODY, required: false)]
    public int $smtpPort = 587;
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $draftsFolder = null;
    #[Parameter(in: Parameter::BODY, required: false)]
    public bool $systemConnection = false;
}
