<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AdminAgentConnectionAddressPostRequestDto extends RequestDto
{
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $connectionId;
    /** @var string the channel id */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $externalAddress = '';
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $displayName = null;
    #[Parameter(in: Parameter::BODY, required: false)]
    public string $behaviour = 'ANSWER_MENTIONS';
    #[Parameter(in: Parameter::BODY, required: false)]
    public string $postingUse = 'NONE';
    #[Parameter(in: Parameter::BODY, required: false)]
    public string $audience = 'INTERNAL';
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $requiredAccountRight = null;
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $purposeName = null;
}
