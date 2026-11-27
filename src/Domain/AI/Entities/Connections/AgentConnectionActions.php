<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionActions;
use DDD\Domain\AI\Services\Connections\AgentConnectionActionsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionAction}.
 *
 * @property AgentConnectionAction[] $elements
 * @method AgentConnectionAction getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionAction[] getElements()
 * @method AgentConnectionAction|null first()
 * @method static AgentConnectionActionsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionActions::class, forceDBEntityModelCreation: true)]
class AgentConnectionActions extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionActionsService::class;
}
