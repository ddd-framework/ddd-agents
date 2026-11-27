<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionCalendarFreeBusyRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string[] Calendar ids (also colleagues' addresses); default primary */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $calendarIds = [];
    /** @var DateTime The start, ISO 8601 with offset */
    #[Parameter(in: Parameter::BODY, required: true)]
    public DateTime $fromTime;
    /** @var DateTime The end, ISO 8601 with offset */
    #[Parameter(in: Parameter::BODY, required: true)]
    public DateTime $toTime;
}
