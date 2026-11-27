<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionsListMcpResponseDto extends RestResponseDto
{
    /** @var AgentConnectionSummaryDto[] Every connection usable here, of every provider and mode */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public array $connections = [];

    /**
     * @var string|null Outside production: only these recipients receive anything that is sent (mail, invitations,
     *     chat posts); every other address is refused even with the level. Null in production.
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $stageRecipientNote = null;
}
