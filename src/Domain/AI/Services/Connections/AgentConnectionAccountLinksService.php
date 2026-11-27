<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLink;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLinks;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see AgentConnectionAccountLink}.
 *
 * @method AgentConnectionAccountLink|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionAccountLinks findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionAccountLink update(AgentConnectionAccountLink &$entity)
 */
class AgentConnectionAccountLinksService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionAccountLink::class;
}
