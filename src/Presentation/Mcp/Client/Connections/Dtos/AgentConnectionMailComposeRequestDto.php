<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionMailComposeRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string[] */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $to = [];
    /** @var string[] */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $cc = [];
    /** @var string[] */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $bcc = [];
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $subject = '';
    /** @var string Plain text */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $bodyText = '';
    /** @var string|null Reply into this thread */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $threadId = null;
    /** @var string|null The Message-ID header of the mail answered */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $inReplyToInternetMessageId = null;
}
