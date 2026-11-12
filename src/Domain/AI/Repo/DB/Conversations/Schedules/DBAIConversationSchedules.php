<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedules;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIConversationSchedules|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationSchedules extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationSchedule::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationSchedules::class;
}
