<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings;

use DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbedding;
use DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbeddings;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIConversationMessageEmbeddings|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationMessageEmbeddings extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationMessageEmbedding::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationMessageEmbeddings::class;

    /** The embedding row for a given message id, or null — used by the embed seam to UPSERT (one row per message). */
    public function findByMessageId(int $aiConversationMessageId): ?AIConversationMessageEmbedding
    {
        $alias = self::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.aiConversationMessageId = :messageId")
            ->setParameter('messageId', $aiConversationMessageId)
            ->setMaxResults(1);
        return $this->find($queryBuilder)?->first();
    }
}
