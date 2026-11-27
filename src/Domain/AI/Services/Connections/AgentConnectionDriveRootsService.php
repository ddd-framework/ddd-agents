<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoot;
use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoots;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see AgentConnectionDriveRoot}.
 *
 * @method AgentConnectionDriveRoot|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionDriveRoots findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionDriveRoot update(AgentConnectionDriveRoot &$entity)
 */
class AgentConnectionDriveRootsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionDriveRoot::class;
}
