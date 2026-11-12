<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationToolCallBatches;
use DDD\Domain\AI\Services\Conversations\AIConversationToolCallBatchesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property AIConversationToolCallBatch[] $elements;
 * @method AIConversationToolCallBatch|null first()
 * @method AIConversationToolCallBatch|null getByUniqueKey(string $uniqueKey)
 * @method AIConversationToolCallBatch[] getElements()
 * @method static AIConversationToolCallBatchesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, repoClass: DBAIConversationToolCallBatches::class)]
class AIConversationToolCallBatches extends EntitySet
{
    use QueryOptionsTrait;

    // The flat getService() resolver would look under the domain's `Services` namespace; the service lives under
    // Services\Conversations\, so pin it explicitly (the AIConversationDelegationBatches precedent).
    public const string SERVICE_NAME = AIConversationToolCallBatchesService::class;
}
