<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationToolCallBatch;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AIConversationToolCallBatch find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationToolCallBatchModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationToolCallBatch extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationToolCallBatch::class;
    public const string BASE_ORM_MODEL = DBAIConversationToolCallBatchModel::class;

    /**
     * Read-rights anchored on the conversation ({@see AgentAccountRightsService::applyAccountReadRights()}, leftJoin) — a batch is visible iff
     * its {@see AIConversationToolCallBatch::$conversation} is, the {@see DBAIConversationDelegationBatch} precedent.
     * The settle / deadline / sweep SQL runs worker-side with no auth and bypasses this (like the run-claim queries).
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, static::getBaseModelAlias(), 'conversation');
    }
}
