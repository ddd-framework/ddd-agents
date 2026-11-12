<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationToolCallBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationToolCallBatches;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationToolCallBatch;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationToolCallBatches;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * The coordinator service of the queued tool-call batches. This first
 * increment carries only the entity binding; the settle transaction, the deadline flip and the sweeps arrive with
 * the runtime increment.
 *
 * @method AIConversationToolCallBatch find(string|int|null $entityId, bool $useEntityRegistrCache = true)
 * @method AIConversationToolCallBatches findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AIConversationToolCallBatch update(AIConversationToolCallBatch $entity)
 * @method DBAIConversationToolCallBatch getEntityRepoClassInstance()
 * @method DBAIConversationToolCallBatches getEntitySetRepoClassInstance()
 */
class AIConversationToolCallBatchesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AIConversationToolCallBatch::class;
}
