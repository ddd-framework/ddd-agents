<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AIConversationCompactionNode find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBAIConversationCompactionNodeModel $ormInstance
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBConversationCompactionNode extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationCompactionNode::class;
    // Points at the regenerated, AI-prefixed model (table AIConversationCompactionNodes plus the host's table prefix).
    public const string BASE_ORM_MODEL = DBAIConversationCompactionNodeModel::class;

    /**
     * Rights scope via the owning conversation's account — the account read rights of
     * {@see AgentAccountRightsService::applyAccountReadRights()}, as
     * {@see \DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage::applyReadRightsQuery} (a compaction
     * node is no more public than the messages it summarizes; the node has no account of its own, so the rights run on
     * its `aiConversation`). Returns true wherever conditions were added so ExpandOptions merges them on nested
     * loads (ddd-rights-specialist: never `return parent::` after adding WHEREs).
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
