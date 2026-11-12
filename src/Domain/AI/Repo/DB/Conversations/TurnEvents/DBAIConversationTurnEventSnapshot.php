<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\TurnEvents;

use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEventSnapshot;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AIConversationTurnEventSnapshot find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationTurnEventSnapshotModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationTurnEventSnapshot extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationTurnEventSnapshot::class;
    public const string BASE_ORM_MODEL = DBAIConversationTurnEventSnapshotModel::class;

    /** A snapshot carries skill and tool names only, but it is an operator artifact: admins read it, nobody else. */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAdminOnlyReadRights($queryBuilder, static::getBaseModelAlias());
    }
}
