<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Infrastructure\Services\Service;

/**
 * The accounts the application runs its agent evaluations on — left out of the decision statistics besides the rows
 * marked as eval runs. The default knows none. An application puts a same-path subclass under its own root namespace;
 * the core resolves this service only through `DDDService::instance()->getService(AgentEvalAccountsService::class)`.
 */
class AgentEvalAccountsService extends Service
{
    /**
     * The ids of the evaluation accounts. The default: none.
     *
     * @return int[]
     */
    public function getEvalAccountIds(): array
    {
        return [];
    }
}
