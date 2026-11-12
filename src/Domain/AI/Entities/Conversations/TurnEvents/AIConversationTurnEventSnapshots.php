<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\TurnEvents;

use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventSnapshots;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * @property AIConversationTurnEventSnapshot[] $elements
 * @method AIConversationTurnEventSnapshot[] getElements()
 * @method static AIConversationTurnEventsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationTurnEventSnapshots::class)]
class AIConversationTurnEventSnapshots extends EntitySet
{
    public const string SERVICE_NAME = AIConversationTurnEventsService::class;
}
