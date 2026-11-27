<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionBusyPeriod}.
 *
 * @property AgentConnectionBusyPeriod[] $elements
 * @method AgentConnectionBusyPeriod[] getElements()
 * @method AgentConnectionBusyPeriod|null first()
 */
class AgentConnectionBusyPeriods extends ObjectSet
{
}
