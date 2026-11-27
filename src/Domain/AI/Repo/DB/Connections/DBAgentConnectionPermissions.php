<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionPermissions;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionPermissions|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionPermissions extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionPermission::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionPermissions::class;
}
