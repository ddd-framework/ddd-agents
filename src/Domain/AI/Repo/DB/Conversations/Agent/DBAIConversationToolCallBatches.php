<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationToolCallBatches;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIConversationToolCallBatches find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationToolCallBatches extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationToolCallBatch::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationToolCallBatches::class;
}
