<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\TurnEvents;

use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AIConversationTurnEvent find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationTurnEventModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationTurnEvent extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationTurnEvent::class;
    public const string BASE_ORM_MODEL = DBAIConversationTurnEventModel::class;

    /**
     * Rights scope via the owning conversation's account ({@see AgentAccountRightsService::applyAccountReadRights()}) — as the compaction nodes
     * ({@see \DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBConversationCompactionNode::applyReadRightsQuery}): an
     * event is no more public than the conversation it belongs to. Returns true wherever conditions were added.
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
