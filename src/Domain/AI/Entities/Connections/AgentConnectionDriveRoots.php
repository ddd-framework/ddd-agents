<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionDriveRoots;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveRootsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionDriveRoot}.
 *
 * @property AgentConnectionDriveRoot[] $elements
 * @method AgentConnectionDriveRoot getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionDriveRoot[] getElements()
 * @method AgentConnectionDriveRoot|null first()
 * @method static AgentConnectionDriveRootsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionDriveRoots::class, forceDBEntityModelCreation: true)]
class AgentConnectionDriveRoots extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = AgentConnectionDriveRootsService::class;
}
