<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Embeddings;

use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddings;
use DDD\Domain\AI\Services\Conversations\AIConversationMessageEmbeddingsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Collection of {@see AIConversationMessageEmbedding} — the recall-embedding rows. The vector search lives on the
 * repo {@see DBAIConversationMessageEmbeddings}; this set is the in-memory carrier.
 *
 * @property AIConversationMessageEmbedding[] $elements
 * @method AIConversationMessageEmbedding getByUniqueKey(string $uniqueKey)
 * @method AIConversationMessageEmbedding[] getElements()
 * @method AIConversationMessageEmbedding first()
 * @method static AIConversationMessageEmbeddingsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAIConversationMessageEmbeddings::class)]
class AIConversationMessageEmbeddings extends EntitySet
{
    use QueryOptionsTrait;

    // The service lives under Services/Conversations/ (with its siblings), not the flat Services/ path the
    // getService() convention resolves by default — name it explicitly.
    public const string SERVICE_NAME = AIConversationMessageEmbeddingsService::class;
}
