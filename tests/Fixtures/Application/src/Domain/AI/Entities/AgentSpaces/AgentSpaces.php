<?php

declare(strict_types=1);

namespace App\Domain\AI\Entities\AgentSpaces;

use App\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaces;
use App\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;

/**
 * Fixture application: the set of the application's {@see AgentSpace} — the package set, bound to the application's
 * set repository and service. An application entity does not fall back to a set of another root namespace: without
 * this class neither `AgentSpace::getService()` nor the service's set repository would resolve.
 *
 * @property AgentSpace[] $elements
 * @method AgentSpace[] getElements()
 * @method AgentSpace|null first()
 * @method AgentSpace getByUniqueKey(string $uniqueKey)
 * @method static AgentSpacesService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentSpaces::class)]
class AgentSpaces extends \DDD\Domain\AI\Entities\AgentSpaces\AgentSpaces
{
    public const string SERVICE_NAME = AgentSpacesService::class;
}
