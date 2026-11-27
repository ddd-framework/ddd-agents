<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A busy period of a free/busy answer.
 */
class AgentConnectionBusyPeriod extends ValueObject
{
    public string $calendarId = '';
    public ?DateTime $startsAt = null;
    public ?DateTime $endsAt = null;
}
