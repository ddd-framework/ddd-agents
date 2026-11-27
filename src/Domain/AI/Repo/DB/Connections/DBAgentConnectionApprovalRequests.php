<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequests;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentConnectionApprovalRequests|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentConnectionApprovalRequests extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentConnectionApprovalRequest::class;
    public const string BASE_ENTITY_SET_CLASS = AgentConnectionApprovalRequests::class;
}
