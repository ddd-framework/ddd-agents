<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionReleases;
use DDD\Domain\AI\Services\Connections\AgentConnectionReleasesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionRelease}.
 *
 * @property AgentConnectionRelease[] $elements
 * @method AgentConnectionRelease getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionRelease[] getElements()
 * @method AgentConnectionRelease|null first()
 * @method static AgentConnectionReleasesService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionReleases::class, forceDBEntityModelCreation: true)]
class AgentConnectionReleases extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionReleasesService::class;
}
