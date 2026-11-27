<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionApprovalRequests;
use DDD\Domain\AI\Services\Connections\AgentConnectionApprovalRequestsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * A list of {@see AgentConnectionApprovalRequest}.
 *
 * @property AgentConnectionApprovalRequest[] $elements
 * @method AgentConnectionApprovalRequest getByUniqueKey(string $uniqueKey)
 * @method AgentConnectionApprovalRequest[] getElements()
 * @method AgentConnectionApprovalRequest|null first()
 * @method static AgentConnectionApprovalRequestsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentConnectionApprovalRequests::class, forceDBEntityModelCreation: true)]
class AgentConnectionApprovalRequests extends EntitySet
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Connections\…`) */
    public const string SERVICE_NAME = AgentConnectionApprovalRequestsService::class;
}
