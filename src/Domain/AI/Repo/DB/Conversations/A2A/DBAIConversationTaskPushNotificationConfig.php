<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\A2A;

use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfig;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AIConversationTaskPushNotificationConfig find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationTaskPushNotificationConfigModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationTaskPushNotificationConfig extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationTaskPushNotificationConfig::class;
    public const string BASE_ORM_MODEL = DBAIConversationTaskPushNotificationConfigModel::class;

    /**
     * Read-rights anchored on the watched conversation (the DBAIConversationDelegationBatch pattern, one join
     * deeper): a push config is visible iff its {@see AIConversationTaskPushNotificationConfig::$aiConversation} is.
     * No accountId is denormalized onto the config (the conversation stays the single source of truth). The
     * delivery-worker reads run rights-bypassed (worker-side, like the reaper queries).
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, static::getBaseModelAlias(), 'aiConversation');
    }
}
