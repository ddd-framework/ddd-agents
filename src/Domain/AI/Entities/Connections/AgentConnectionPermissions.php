<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionPermissions;
use DDD\Domain\AI\Services\Connections\AgentConnectionPermissionsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionPermission}.
 *
 * @property AgentConnectionPermission[] $elements
 * @method AgentConnectionPermission getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionPermission[] getElements()
 * @method AgentConnectionPermission|null first()
 * @method static AgentConnectionPermissionsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionPermissions::class, forceDBEntityModelCreation: true)]
class AgentConnectionPermissions extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionPermissionsService::class;
}
