<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\TurnEvents;

use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEventSnapshots;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIConversationTurnEventSnapshots find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationTurnEventSnapshots extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationTurnEventSnapshot::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationTurnEventSnapshots::class;
}
