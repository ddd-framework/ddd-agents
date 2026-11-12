<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatches;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property AIConversationDelegationBatch[] $elements;
 * @method AIConversationDelegationBatch|null first()
 * @method AIConversationDelegationBatch|null getByUniqueKey(string $uniqueKey)
 * @method AIConversationDelegationBatch[] getElements()
 * @method static AIConversationDelegationBatchesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversationDelegationBatches::class)]
class AIConversationDelegationBatches extends EntitySet
{
    use QueryOptionsTrait;

    // The flat getService() resolver would look under the domain's `Services` namespace; the service lives under
    // Services\Conversations\, so pin it explicitly (the AIConversations / AIConversationMessages precedent).
    public const string SERVICE_NAME = AIConversationDelegationBatchesService::class;
}
