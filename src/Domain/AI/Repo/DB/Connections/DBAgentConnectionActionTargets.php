<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionActionTargets;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionActionTargets|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionActionTargets extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionActionTarget::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionActionTargets::class;
}
