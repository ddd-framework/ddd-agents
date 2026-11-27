<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionFile}.
 *
 * @property AgentConnectionFile[] $elements
 * @method AgentConnectionFile[] getElements()
 * @method AgentConnectionFile|null first()
 */
class AgentConnectionFiles extends ObjectSet
{
}
