<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermissions;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see AgentConnectionPermission}.
 *
 * @method AgentConnectionPermission|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionPermissions findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionPermission update(AgentConnectionPermission &$entity)
 */
class AgentConnectionPermissionsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionPermission::class;
}
