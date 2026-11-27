<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionInboundEvents;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionInboundEvents|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionInboundEvents extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionInboundEvent::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionInboundEvents::class;
}
