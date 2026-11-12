<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\A2A;

use DDD\Domain\AI\Repo\DB\Conversations\A2A\DBAIConversationTaskPushNotificationConfigs;
use DDD\Domain\AI\Services\Conversations\AIConversationTaskPushNotificationConfigsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property AIConversationTaskPushNotificationConfig[] $elements;
 * @method AIConversationTaskPushNotificationConfig|null first()
 * @method AIConversationTaskPushNotificationConfig|null getByUniqueKey(string $uniqueKey)
 * @method AIConversationTaskPushNotificationConfig[] getElements()
 * @method static AIConversationTaskPushNotificationConfigsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversationTaskPushNotificationConfigs::class)]
class AIConversationTaskPushNotificationConfigs extends EntitySet
{
    use QueryOptionsTrait;

    // The flat getService() resolver would look under the domain's `Services` namespace; the service lives under
    // Services\Conversations\, so pin it explicitly (the AIConversationDelegationBatches precedent).
    public const string SERVICE_NAME = AIConversationTaskPushNotificationConfigsService::class;
}
