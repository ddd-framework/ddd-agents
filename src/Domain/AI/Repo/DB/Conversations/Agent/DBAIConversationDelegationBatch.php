<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AIConversationDelegationBatch find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationDelegationBatchModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationDelegationBatch extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationDelegationBatch::class;
    public const string BASE_ORM_MODEL = DBAIConversationDelegationBatchModel::class;

    /**
     * Read-rights anchored on the parent conversation ({@see AgentAccountRightsService::applyAccountReadRights()}, leftJoin) — a batch is visible
     * iff its {@see AIConversationDelegationBatch::$parentConversation} is, mirroring {@see \DDD\Domain\AI\Repo\DB\Conversations\DBAIConversation}
     * one join deeper. No `accountId` is denormalized onto the batch (the conversation is the single source of truth).
     * The settle/reaper SQL runs worker-side with no auth and bypasses this (like the run-claim / reaper queries).
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, static::getBaseModelAlias(), 'parentConversation');
    }
}
