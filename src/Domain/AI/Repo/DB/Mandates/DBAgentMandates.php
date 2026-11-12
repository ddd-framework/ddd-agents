<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Mandates;

use DDD\Domain\AI\Entities\Mandates\AgentMandates;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentMandates find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentMandates extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentMandate::class;
    public const string BASE_ENTITY_SET_CLASS = AgentMandates::class;
}
