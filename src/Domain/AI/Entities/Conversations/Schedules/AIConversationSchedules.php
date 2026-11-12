<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationSchedules;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Collection of {@see AIConversationSchedule}.
 *
 * @property AIConversationSchedule[] $elements
 * @method AIConversationSchedule[] getElements()
 * @method AIConversationSchedule|null first()
 * @method AIConversationSchedule getByUniqueKey(string $uniqueKey)
 * @method static AIConversationSchedulesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationSchedules::class)]
class AIConversationSchedules extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AIConversationSchedulesService::class;
}
