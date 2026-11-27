<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionActions;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionActions|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionActions extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionAction::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionActions::class;
}
