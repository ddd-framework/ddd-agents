<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes;

use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\Dtos\RequestDto;

/** A tool request with a day and a moment, to read how the two parameter types are documented. */
class ProbeRequestWithDateParameters extends RequestDto
{
    /** @var Date|null The first day of the period */
    public ?Date $firstDay = null;

    /** @var DateTime|null The moment the task runs */
    public ?DateTime $runAt = null;
}
