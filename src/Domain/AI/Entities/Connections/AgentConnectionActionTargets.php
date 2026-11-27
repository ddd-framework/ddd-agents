<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionActionTargets;
use DDD\Domain\AI\Services\Connections\AgentConnectionActionTargetsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * A list of {@see AgentConnectionActionTarget}.
 *
 * @property AgentConnectionActionTarget[] $elements
 * @method AgentConnectionActionTarget getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionActionTarget[] getElements()
 * @method AgentConnectionActionTarget|null first()
 * @method static AgentConnectionActionTargetsService getService()
 * @phpstan-consistent-constructor
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionActionTargets::class, forceDBEntityModelCreation: true)]
class AgentConnectionActionTargets extends EntitySet
{
    use ChangeHistoryTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionActionTargetsService::class;
}
