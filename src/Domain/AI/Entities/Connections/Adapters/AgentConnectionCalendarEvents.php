<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionCalendarEvent}.
 *
 * @property AgentConnectionCalendarEvent[] $elements
 * @method AgentConnectionCalendarEvent[] getElements()
 * @method AgentConnectionCalendarEvent|null first()
 */
class AgentConnectionCalendarEvents extends ObjectSet
{
}
