<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\TraceEvents;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AIConversationTraceEvent find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationTraceEventModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationTraceEvent extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationTraceEvent::class;
    public const string BASE_ORM_MODEL = DBAIConversationTraceEventModel::class;

    /**
     * Rights scope via the owning conversation's account ({@see AgentAccountRightsService::applyAccountReadRights()}) — as the turn events
     * ({@see \DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEvent::applyReadRightsQuery}): an
     * event is no more public than the conversation it belongs to; an event not yet linked to a conversation is
     * visible to admins only. Returns true wherever conditions were added.
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
