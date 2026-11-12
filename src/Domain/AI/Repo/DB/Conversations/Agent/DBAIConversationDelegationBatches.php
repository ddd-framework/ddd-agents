<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatches;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIConversationDelegationBatches find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationDelegationBatches extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationDelegationBatch::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationDelegationBatches::class;
}
