<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionInboundEvents;
use DDD\Domain\AI\Services\Connections\AgentConnectionInboundEventsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionInboundEvent}.
 *
 * @property AgentConnectionInboundEvent[] $elements
 * @method AgentConnectionInboundEvent getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionInboundEvent[] getElements()
 * @method AgentConnectionInboundEvent|null first()
 * @method static AgentConnectionInboundEventsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionInboundEvents::class, forceDBEntityModelCreation: true)]
class AgentConnectionInboundEvents extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionInboundEventsService::class;
}
