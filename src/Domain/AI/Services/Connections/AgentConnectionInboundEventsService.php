<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionInboundEvent;
use DDD\Domain\AI\Entities\Connections\AgentConnectionInboundEvents;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see AgentConnectionInboundEvent}.
 *
 * @method AgentConnectionInboundEvent|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionInboundEvents findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionInboundEvent update(AgentConnectionInboundEvent &$entity)
 */
class AgentConnectionInboundEventsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionInboundEvent::class;
}
