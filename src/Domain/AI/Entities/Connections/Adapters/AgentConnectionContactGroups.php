<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionContactGroup}.
 *
 * @property AgentConnectionContactGroup[] $elements
 * @method AgentConnectionContactGroup[] getElements()
 * @method AgentConnectionContactGroup|null first()
 */
class AgentConnectionContactGroups extends ObjectSet
{
}
