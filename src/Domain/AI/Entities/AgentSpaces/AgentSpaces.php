<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaces;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * The things an agent works for (see {@see AgentSpace}).
 *
 * @property AgentSpace[] $elements
 * @method AgentSpace getByUniqueKey(string $uniqueKey)
 * @method AgentSpace[] getElements()
 * @method AgentSpace first()
 * @method static AgentSpacesService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentSpaces::class)]
class AgentSpaces extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AgentSpacesService::class;
}
