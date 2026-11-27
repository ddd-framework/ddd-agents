<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionActionTarget;
use DDD\Domain\AI\Entities\Connections\AgentConnectionActionTargets;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see AgentConnectionActionTarget}.
 *
 * @method AgentConnectionActionTarget|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionActionTargets findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionActionTarget update(AgentConnectionActionTarget &$entity)
 */
class AgentConnectionActionTargetsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionActionTarget::class;
}
