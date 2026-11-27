<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionAccountLinks;
use DDD\Domain\AI\Services\Connections\AgentConnectionAccountLinksService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionAccountLink}.
 *
 * @property AgentConnectionAccountLink[] $elements
 * @method AgentConnectionAccountLink getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionAccountLink[] getElements()
 * @method AgentConnectionAccountLink|null first()
 * @method static AgentConnectionAccountLinksService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionAccountLinks::class, forceDBEntityModelCreation: true)]
class AgentConnectionAccountLinks extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionAccountLinksService::class;
}
