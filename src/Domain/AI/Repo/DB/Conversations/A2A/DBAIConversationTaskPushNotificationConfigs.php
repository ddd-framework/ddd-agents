<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\A2A;

use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfigs;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIConversationTaskPushNotificationConfigs find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationTaskPushNotificationConfigs extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationTaskPushNotificationConfig::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationTaskPushNotificationConfigs::class;
}
