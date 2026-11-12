<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI\Probes;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of an application's `listMandates`: the application names the space by the id of its own entity. */
class ProbeShopMandateListRequestDto extends RequestDto
{
    /** @var int|null The shop whose view to list. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $shopId = null;
}
