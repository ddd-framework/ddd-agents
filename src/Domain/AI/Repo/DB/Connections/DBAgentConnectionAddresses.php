<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionAddresses;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionAddresses|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionAddresses extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionAddress::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionAddresses::class;
}
