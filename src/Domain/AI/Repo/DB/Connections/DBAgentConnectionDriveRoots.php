<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoots;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionDriveRoots|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionDriveRoots extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionDriveRoot::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionDriveRoots::class;
}
