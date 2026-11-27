<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionRelease;
use DDD\Domain\AI\Entities\Connections\AgentConnectionReleases;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see AgentConnectionRelease}.
 *
 * @method AgentConnectionRelease|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionReleases findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionRelease update(AgentConnectionRelease &$entity)
 */
class AgentConnectionReleasesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionRelease::class;
}
