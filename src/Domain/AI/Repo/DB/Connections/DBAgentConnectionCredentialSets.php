<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionCredentialSets;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionCredentialSets|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionCredentialSets extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionCredentials::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionCredentialSets::class;
}
