<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionAddress;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddresses;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see AgentConnectionAddress}.
 *
 * @method AgentConnectionAddress|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionAddresses findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionAddress update(AgentConnectionAddress &$entity)
 */
class AgentConnectionAddressesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionAddress::class;
}
