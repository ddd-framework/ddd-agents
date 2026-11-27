<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionMailMessage}.
 *
 * @property AgentConnectionMailMessage[] $elements
 * @method AgentConnectionMailMessage[] getElements()
 * @method AgentConnectionMailMessage|null first()
 */
class AgentConnectionMailMessages extends ObjectSet
{
}
