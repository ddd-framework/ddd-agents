<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionAddresses;
use DDD\Domain\AI\Services\Connections\AgentConnectionAddressesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionAddress}.
 *
 * @property AgentConnectionAddress[] $elements
 * @method AgentConnectionAddress getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionAddress[] getElements()
 * @method AgentConnectionAddress|null first()
 * @method static AgentConnectionAddressesService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionAddresses::class, forceDBEntityModelCreation: true)]
class AgentConnectionAddresses extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionAddressesService::class;
}
