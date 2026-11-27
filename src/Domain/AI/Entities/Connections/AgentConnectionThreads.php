<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionThreads;
use DDD\Domain\AI\Services\Connections\AgentConnectionThreadsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionThread}.
 *
 * @property AgentConnectionThread[] $elements
 * @method AgentConnectionThread getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionThread[] getElements()
 * @method AgentConnectionThread|null first()
 * @method static AgentConnectionThreadsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionThreads::class, forceDBEntityModelCreation: true)]
class AgentConnectionThreads extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionThreadsService::class;
}
