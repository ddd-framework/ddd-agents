<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Environments;

use DDD\Infrastructure\Services\Service;

/**
 * The environment name of this process — stamped on every conversation row (the `environment` column) so processes of
 * several environments that share one database each drive only their own rows (production's reaper never revives a
 * development workspace's run). An application with several environments on one database puts a same-path subclass
 * under its own root namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AgentEnvironmentNamesService::class)`.
 */
class AgentEnvironmentNamesService extends Service
{
    /**
     * The environment name of production. Rows written before the column existed (unstamped) count as production's.
     */
    public const string ENVIRONMENT_PRODUCTION = 'prod';

    /**
     * The environment name of this process. The default: {@see ENVIRONMENT_PRODUCTION} (a single-environment
     * application; it adopts unstamped rows like production).
     *
     * @return string
     */
    public function getCurrentEnvironmentName(): string
    {
        return static::ENVIRONMENT_PRODUCTION;
    }
}
