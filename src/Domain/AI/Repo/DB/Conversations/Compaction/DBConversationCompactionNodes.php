<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AIConversationCompactionNodes find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBConversationCompactionNodes extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBConversationCompactionNode::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationCompactionNodes::class;

    /**
     * The columns a search reads of a matched node: which conversation and which message range it covers. The
     * search resolves a node to the messages of that range; the summary text, the preserved facts and the vector it
     * ranked on are never read there.
     */
    public const array SEARCH_HIT_LOAD_COLUMNS = ['id', 'aiConversationId', 'coveredFromMessageIndex', 'coveredToMessageIndex'];

    /**
     * Nearest compaction nodes to a query embedding WITHIN one conversation, by cosine distance (the
     * "search within compactions" RAPTOR layer). Searches ALL tree levels (L0 leaves + L1/L2 roll-ups) so a
     * conceptual query hits a node at its resolution. Mirrors {@see \DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages::findSimilarByEmbedding}
     * over the per-node {@see \DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode::$summaryEmbedding}.
     * Skips un-embedded nodes (`summaryEmbedding != <zero vector>` — MariaDB NOT-NULL vector default). Embedding generation lives in the SERVICE.
     *
     * @return AIConversationCompactionNodes|null
     */
    public function findSimilarByEmbedding(TextEmbedding $queryEmbedding, int $conversationId, int $limit = 5): ?AIConversationCompactionNodes
    {
        if ($queryEmbedding->isEmpty()) {
            return AIConversationCompactionNodes::newInstance();
        }
        $queryBuilder = $this->buildVectorSearchQueryBuilder($queryEmbedding, $limit);
        $queryBuilder
            ->andWhere(self::getBaseModelAlias() . '.aiConversationId = :conversationId')
            ->setParameter('conversationId', $conversationId);
        return $this->findWithSearchHitColumns($queryBuilder);
    }

    /**
     * The CROSS-conversation node search: rank the compaction-node summaries of the conversations of ONE account by
     * cosine distance to the query — the twin of {@see self::findSimilarByEmbedding()} filtered on the conversation's
     * `accountId` instead of one conversation. The account filter is part of the query itself and holds for every
     * caller, an account with the admin role included; the entity read rights apply on top. The RAPTOR summary layer
     * over the account's whole history: recalls a compacted topic from any past conversation even when no single raw
     * leaf matches. Skips un-embedded nodes (`summaryEmbedding != <zero vector>` — MariaDB NOT-NULL vector default).
     *
     * @return AIConversationCompactionNodes|null
     */
    public function findSimilarAcrossConversationsByEmbedding(Account $account, TextEmbedding $queryEmbedding, int $limit = 20): ?AIConversationCompactionNodes
    {
        if ($queryEmbedding->isEmpty() || !isset($account->id)) {
            return AIConversationCompactionNodes::newInstance();
        }
        return $this->findWithSearchHitColumns(
            $this->buildVectorSearchQueryBuilderForConversationsOfAccount($account, $queryEmbedding, $limit)
        );
    }

    /**
     * The query of {@see findSimilarAcrossConversationsByEmbedding()}: the vector search of this repo, restricted to
     * the conversations whose `accountId` is the account's. The restriction is a filter of the search, not a right
     * (see {@see \DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages::restrictToConversationsOfAccount()}).
     */
    public function buildVectorSearchQueryBuilderForConversationsOfAccount(Account $account, TextEmbedding $queryEmbedding, int $limit): DoctrineQueryBuilder
    {
        $alias = self::getBaseModelAlias();
        $conversationAlias = $alias . '_aiConversation_ofAccount';
        $queryBuilder = $this->buildVectorSearchQueryBuilder($queryEmbedding, $limit);
        $queryBuilder
            ->innerJoin("$alias.aiConversation", $conversationAlias)
            ->andWhere("$conversationAlias.accountId = :conversationAccountId")
            ->setParameter('conversationAccountId', (int)$account->id);
        return $queryBuilder;
    }

    /**
     * The part every vector search of this repo shares: un-embedded nodes excluded, the rows ordered by the exact
     * distance of their summary embedding to the search vector. The caller adds the scope (one conversation, the
     * conversations of one account).
     *
     * The `+ 0` in the ORDER BY makes MariaDB compute the distance over the rows the scope selects. The schema
     * declares a vector index on `summaryEmbedding`; with or without it on the table, the search does not order by
     * it: a vector index returns the nearest rows of the WHOLE table and the scope filter would run on those
     * afterwards (see {@see \DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages::EXACT_DISTANCE_TO_SEARCH_VECTOR}).
     */
    protected function buildVectorSearchQueryBuilder(TextEmbedding $queryEmbedding, int $limit): DoctrineQueryBuilder
    {
        $alias = self::getBaseModelAlias();
        $vectorValues = $queryEmbedding->vectorValues ?? [];
        $queryBuilder = self::createQueryBuilder(true); // true: a caller's join needs the root alias
        $queryBuilder
            // EMBEDDED filter: MariaDB forbids NULL on the vector index → an un-embedded node holds the ZERO-vector DEFAULT,
            // never NULL. "Has a real summary embedding" = NOT the zero vector (matches the AccountAIMemory pattern).
            ->andWhere("$alias.summaryEmbedding != VEC_FROM_TEXT(:zeroVector)")
            ->addOrderBy("COSINE_DISTANCE($alias.summaryEmbedding, VEC_FROM_TEXT(:searchVector)) + 0", 'ASC')
            ->setParameter('searchVector', '[' . implode(',', $vectorValues) . ']')
            ->setParameter('zeroVector', '[' . str_repeat('0,', max(0, count($vectorValues) - 1)) . '0]')
            ->setMaxResults($limit);
        return $queryBuilder;
    }

    /**
     * Load matched nodes through the {@see self::SEARCH_HIT_LOAD_COLUMNS} partial SELECT: a node row carries its
     * summary text, its preserved facts and a 1536-float vector, none of which the search reads. Cache-bypassed (a
     * partial-hydrated entity must not enter the process-static registry) + snapshot/restore so the projection never
     * leaks into another query in this request.
     */
    protected function findWithSearchHitColumns(DoctrineQueryBuilder $queryBuilder): ?AIConversationCompactionNodes
    {
        AIConversationCompactionNodes::setDefaultQueryOptionsSnapshot();
        try {
            // setSelect() takes the SelectOptions BY REFERENCE — assign to a variable first.
            $select = SelectOptions::fromString(implode(',', self::SEARCH_HIT_LOAD_COLUMNS));
            AIConversationCompactionNodes::getDefaultQueryOptions()->setSelect($select, false);
            return $this->find($queryBuilder, false);
        } finally {
            AIConversationCompactionNodes::restoreDefaultQueryOptionsSnapshot();
        }
    }
}
