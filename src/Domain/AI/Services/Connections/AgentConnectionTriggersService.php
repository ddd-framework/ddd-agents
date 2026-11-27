<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionTrigger;
use DDD\Domain\AI\Entities\Connections\AgentConnectionTriggers;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see AgentConnectionTrigger}.
 *
 * @method AgentConnectionTrigger|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionTriggers findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionTrigger update(AgentConnectionTrigger &$entity)
 */
class AgentConnectionTriggersService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionTrigger::class;
}
