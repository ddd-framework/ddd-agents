<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionThread;
use DDD\Domain\AI\Entities\Connections\AgentConnectionThreads;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Reads and writes of {@see AgentConnectionThread}.
 *
 * @method AgentConnectionThread|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentConnectionThreads findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AgentConnectionThread update(AgentConnectionThread &$entity)
 */
class AgentConnectionThreadsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentConnectionThread::class;
}
