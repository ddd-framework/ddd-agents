<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionThreads;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionThreads|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionThreads extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionThread::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionThreads::class;
}
