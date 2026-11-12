<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `revokeMandate` — get the id from `listMandates` first. */
class MandateRevokeRequestDto extends RequestDto
{
    /** @var int The id of the mandate to revoke (from listMandates). */
    #[Parameter(in: Parameter::BODY, required: true)]
    public int $mandateId;
}
