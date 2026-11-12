<?php

declare(strict_types=1);

namespace App\Domain\AI\Repo\DB\AgentSpaces;

use App\Domain\AI\Entities\AgentSpaces\AgentSpaces;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Fixture application: the package's space set repository over the application's set.
 *
 * @method AgentSpaces|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentSpaces extends \DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaces
{
    public const string BASE_REPO_CLASS = DBAgentSpace::class;
    public const string BASE_ENTITY_SET_CLASS = AgentSpaces::class;
}
