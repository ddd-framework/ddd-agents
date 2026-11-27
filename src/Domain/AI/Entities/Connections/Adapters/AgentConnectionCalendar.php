<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One calendar of a connection.
 */
class AgentConnectionCalendar extends ValueObject
{
    public string $calendarId = '';
    public string $name = '';
    public bool $isPrimary = false;
    public ?string $timeZone = null;
    /** @var string|null owner, writer, reader, freeBusyReader */
    public ?string $accessRole = null;
}
