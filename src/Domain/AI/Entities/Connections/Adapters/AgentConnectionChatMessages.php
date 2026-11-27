<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A list of {@see AgentConnectionChatMessage}.
 *
 * @property AgentConnectionChatMessage[] $elements
 * @method AgentConnectionChatMessage[] getElements()
 * @method AgentConnectionChatMessage|null first()
 */
class AgentConnectionChatMessages extends ObjectSet
{
}
