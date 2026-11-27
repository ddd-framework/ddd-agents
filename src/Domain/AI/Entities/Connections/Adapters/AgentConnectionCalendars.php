<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionCalendar}.
 *
 * @property AgentConnectionCalendar[] $elements
 * @method AgentConnectionCalendar[] getElements()
 * @method AgentConnectionCalendar|null first()
 */
class AgentConnectionCalendars extends ObjectSet
{
}
