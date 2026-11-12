<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AIConversationSchedule find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationScheduleModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationSchedule extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationSchedule::class;
    public const string BASE_ORM_MODEL = DBAIConversationScheduleModel::class;

    /**
     * Read rights anchored on the schedule's OWN `accountId` (the customer sees ONLY own
     * schedules) — the account rule of every agent table ({@see AgentAccountRightsService::applyAccountReadRights()};
     * an application with account hierarchies adds its branches in its same-path subclass of that service). The fire
     * cron runs worker-side rights-deactivated (like the reaper/recovery crons).
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, static::getBaseModelAlias());
    }
}
