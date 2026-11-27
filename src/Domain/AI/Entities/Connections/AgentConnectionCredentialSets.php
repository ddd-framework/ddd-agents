<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionCredentialSets;
use DDD\Domain\AI\Services\Connections\AgentConnectionCredentialSetsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionCredentials}.
 *
 * @property AgentConnectionCredentials[] $elements
 * @method AgentConnectionCredentials getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionCredentials[] getElements()
 * @method AgentConnectionCredentials|null first()
 * @method static AgentConnectionCredentialSetsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionCredentialSets::class, forceDBEntityModelCreation: true)]
class AgentConnectionCredentialSets extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionCredentialSetsService::class;
}
