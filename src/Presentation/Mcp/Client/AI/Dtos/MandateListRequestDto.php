<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `listMandates` — the account's mandates, as one space sees them or all of them. */
class MandateListRequestDto extends RequestDto
{
    /**
     * @var int|null The space whose view to list (the mandates bound to it + the account-wide ones); omit it for the
     * space this conversation works for. A conversation that works for no space lists every mandate of the account.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;
}
