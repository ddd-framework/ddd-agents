<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLinks;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionAccountLinks|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionAccountLinks extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionAccountLink::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionAccountLinks::class;
}
