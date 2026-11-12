<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaces;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentSpaces find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentSpaces extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentSpace::class;
    public const string BASE_ENTITY_SET_CLASS = AgentSpaces::class;
}
