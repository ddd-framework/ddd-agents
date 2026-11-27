<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionCalendarEventCreateRequestDto extends RequestDto
{
    /** @var string|null The connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    #[Parameter(in: Parameter::BODY, required: false)]
    public string $calendarId = 'primary';
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $title = '';
    /** @var DateTime ISO 8601 with offset; for all day the first day */
    #[Parameter(in: Parameter::BODY, required: true)]
    public DateTime $startsAt;
    /** @var DateTime ISO 8601 with offset; for all day the day after the last day */
    #[Parameter(in: Parameter::BODY, required: true)]
    public DateTime $endsAt;
    #[Parameter(in: Parameter::BODY, required: false)]
    public bool $isAllDay = false;
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $location = null;
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $description = null;
    /** @var string[] Attendees receive an invitation */
    #[Parameter(in: Parameter::BODY, required: false)]
    public array $attendees = [];
}
