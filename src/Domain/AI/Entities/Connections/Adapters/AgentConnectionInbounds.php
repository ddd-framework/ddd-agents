<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionInbound}.
 *
 * @property AgentConnectionInbound[] $elements
 * @method AgentConnectionInbound[] getElements()
 * @method AgentConnectionInbound|null first()
 */
class AgentConnectionInbounds extends ObjectSet
{
}
