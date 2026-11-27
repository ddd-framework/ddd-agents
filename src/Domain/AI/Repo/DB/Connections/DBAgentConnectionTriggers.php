<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionTriggers;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionTriggers|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionTriggers extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionTrigger::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionTriggers::class;
}
