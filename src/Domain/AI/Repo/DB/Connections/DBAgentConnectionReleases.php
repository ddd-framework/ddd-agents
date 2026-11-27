<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionReleases;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionReleases|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionReleases extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionRelease::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionReleases::class;
}
