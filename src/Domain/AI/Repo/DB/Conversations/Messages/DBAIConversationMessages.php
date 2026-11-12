<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;

/**
 * @method AIConversationMessages find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationMessages extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAIConversationMessage::class;
    public const string BASE_ENTITY_SET_CLASS = AIConversationMessages::class;

    /**
     * The shortest word a keyword search looks for on PostgreSQL — the default minimum word length of MariaDB's
     * full-text index (`innodb_ft_min_token_size`), so the two engines leave out the same short words.
     */
    public const int KEYWORD_QUERY_MINIMUM_WORD_LENGTH = 3;

    /** The alias every vector search of this repo joins the embedding row under. */
    protected const string MESSAGE_EMBEDDING_JOIN_ALIAS = 'messageEmbedding';

    /**
     * The ORDER BY expression of every vector search in this repo: the cosine distance of the joined embedding row
     * to the search vector, computed EXACTLY over the rows the scope filter (one conversation, one account) selects.
     * The `+ 0` keeps MariaDB from answering the ORDER BY with the vector index `vx_embedding`: that index returns
     * the nearest rows of the WHOLE table, the scope filter runs on those afterwards, and a scope that holds few of
     * the nearest rows gets too few hits or none. A vector index cannot carry the scope column.
     */
    protected const string EXACT_DISTANCE_TO_SEARCH_VECTOR = 'COSINE_DISTANCE(' . self::MESSAGE_EMBEDDING_JOIN_ALIAS . '.embedding, VEC_FROM_TEXT(:searchVector)) + 0';

    /**
     * Nearest messages to a query embedding WITHIN one conversation, by cosine distance (the
     * within-conversation recall key). Ranks over the message's separate embedding row ({@see \DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbedding},
     * the redesign that replaced the per-message vector column): an `INNER JOIN m.messageEmbedding e` makes "is embedded"
     * structural — a message with no embedding row (SIGNAL / tool-call / empty) simply can't match; a legacy row holding
     * the zero vector is filtered out ({@see buildVectorSearchQueryBuilder()}). Embedding generation lives in the SERVICE — this only ranks. Excludes truncated /
     * branched-away messages (`m.truncatedAt IS NULL`). The scope is the conversation id of the query; the read
     * rights of the single-entity repo apply on top.
     *
     * @return AIConversationMessages|null
     */
    public function findSimilarByEmbedding(
        TextEmbedding $queryEmbedding,
        int $conversationId,
        int $limit = 5,
        ?int $fromIndexInConversation = null,
        ?int $toIndexInConversation = null
    ): ?AIConversationMessages {
        if ($queryEmbedding->isEmpty()) {
            return AIConversationMessages::newInstance();
        }
        $alias = self::getBaseModelAlias();
        $queryBuilder = $this->buildVectorSearchQueryBuilder($queryEmbedding, $limit);
        $queryBuilder
            ->andWhere("$alias.aiConversationId = :conversationId")
            ->setParameter('conversationId', $conversationId);
        // Optional index-range scope — the node→messages expansion: a matched compaction node
        // resolves to the best messages WITHIN its [coveredFrom, coveredTo] range (the node summary is never returned).
        if ($fromIndexInConversation !== null) {
            $queryBuilder->andWhere("$alias.indexInConversation >= :fromIndex")
                ->setParameter('fromIndex', $fromIndexInConversation);
        }
        if ($toIndexInConversation !== null) {
            $queryBuilder->andWhere("$alias.indexInConversation <= :toIndex")
                ->setParameter('toIndex', $toIndexInConversation);
        }
        return $this->findWithSearchHitColumns($queryBuilder);
    }

    /**
     * Nearest messages to a query embedding in the conversations of ONE account (the cross-conversation recall).
     * Identical to {@see findSimilarByEmbedding} but filtered on the conversation's `accountId` instead of one
     * `aiConversationId`. The account filter is part of the query itself and holds for every caller, an account with
     * the admin role included; {@see DBAIConversationMessage::applyReadRightsQuery} applies on top. The caller groups
     * the hits by their `aiConversationId`. The `INNER JOIN m.messageEmbedding` excludes un-embedded messages;
     * `m.truncatedAt IS NULL` excludes branched-away ones.
     *
     * @return AIConversationMessages|null
     */
    public function findSimilarAcrossConversationsByEmbedding(Account $account, TextEmbedding $queryEmbedding, int $limit = 20): ?AIConversationMessages
    {
        if ($queryEmbedding->isEmpty() || !isset($account->id)) {
            return AIConversationMessages::newInstance();
        }
        return $this->findWithSearchHitColumns(
            $this->buildVectorSearchQueryBuilderForConversationsOfAccount($account, $queryEmbedding, $limit)
        );
    }

    /**
     * The query of {@see findSimilarAcrossConversationsByEmbedding()}: the vector search of this repo, restricted to
     * the conversations of the account.
     */
    public function buildVectorSearchQueryBuilderForConversationsOfAccount(Account $account, TextEmbedding $queryEmbedding, int $limit): DoctrineQueryBuilder
    {
        $queryBuilder = $this->buildVectorSearchQueryBuilder($queryEmbedding, $limit);
        $this->restrictToConversationsOfAccount($queryBuilder, $account);
        return $queryBuilder;
    }

    /**
     * The part every vector search of this repo shares: the embedding row joined, truncated messages excluded, rows
     * holding the ZERO vector excluded, the rows ordered by their exact distance to the search vector. The caller
     * adds the scope (one conversation, the conversations of one account); a vector search without a scope is not
     * offered.
     *
     * The zero-vector filter is a guard, not a sentinel: the writer never persists a zero vector any more, but rows
     * written before it did (the column default when the embedding call answered an error). MariaDB's cosine distance
     * of a zero-norm vector raises `SQLSTATE[22003] 1690 DOUBLE value is out of range`, so ONE such row in the scope
     * failed the search of the whole account. The WHERE runs before the ORDER BY computes any distance.
     */
    protected function buildVectorSearchQueryBuilder(TextEmbedding $queryEmbedding, int $limit): DoctrineQueryBuilder
    {
        $alias = self::getBaseModelAlias();
        $vectorValues = $queryEmbedding->vectorValues ?? [];
        $queryBuilder = self::createQueryBuilder(true); // true: add SELECT+FROM so the root alias exists before the join (find() detects it + skips re-adding)
        $queryBuilder
            // INNER JOIN the embedding row → only embedded messages are candidates.
            // The relation is mapped to-MANY (inverse OneToMany = LAZY) so a plain message load never eager-hydrates it;
            // joined explicitly here for the cosine ranking. One row per message (UNIQUE aiConversationMessageId).
            ->innerJoin("$alias.messageEmbeddings", self::MESSAGE_EMBEDDING_JOIN_ALIAS)
            ->andWhere("$alias.truncatedAt IS NULL")
            ->andWhere(self::MESSAGE_EMBEDDING_JOIN_ALIAS . '.embedding != VEC_FROM_TEXT(:zeroVector)')
            ->addOrderBy(self::EXACT_DISTANCE_TO_SEARCH_VECTOR, 'ASC')
            ->setParameter('searchVector', '[' . implode(',', $vectorValues) . ']')
            ->setParameter('zeroVector', self::buildZeroVectorText(count($vectorValues)))
            ->setMaxResults($limit);
        return $queryBuilder;
    }

    /** The text form of the all-zero vector of `$dimensions` values (`[0,0,…,0]`), as `VEC_FROM_TEXT` reads it. Pure. */
    public static function buildZeroVectorText(int $dimensions): string
    {
        return '[' . implode(',', array_fill(0, max(1, $dimensions), '0')) . ']';
    }

    /**
     * Keyword hits WITHIN one conversation — a full-text search over the index of the `message` text
     * ({@see buildKeywordSearchMessageIdsQueryBuilder()} for the form per database engine).
     * The LEXICAL complement to the dense vector search (the proven hybrid-recall pattern): catches exact terms, names,
     * ids the embedding glosses over, and needs NO embedding (covers messages that were never embedded). Same projection
     * + rights path as {@see findSimilarByEmbedding}; scoped to one conversation (+ optional index range). A message
     * matches when it holds ANY word of the query; the best match comes first. Empty / stopword-only queries → empty set.
     *
     * @return AIConversationMessages|null
     */
    public function findByKeyword(
        string $query,
        int $conversationId,
        int $limit = 5,
        ?int $fromIndexInConversation = null,
        ?int $toIndexInConversation = null
    ): ?AIConversationMessages {
        if (trim($query) === '') {
            return AIConversationMessages::newInstance();
        }
        $alias = self::getBaseModelAlias();
        $messageIdsQueryBuilder = $this->buildKeywordSearchMessageIdsQueryBuilder($query, $limit);
        $messageIdsQueryBuilder
            ->andWhere("$alias.aiConversationId = :conversationId")
            ->setParameter('conversationId', $conversationId);
        if ($fromIndexInConversation !== null) {
            $messageIdsQueryBuilder->andWhere("$alias.indexInConversation >= :fromIndex")->setParameter('fromIndex', $fromIndexInConversation);
        }
        if ($toIndexInConversation !== null) {
            $messageIdsQueryBuilder->andWhere("$alias.indexInConversation <= :toIndex")->setParameter('toIndex', $toIndexInConversation);
        }
        return $this->findMessagesRankedByMessageIdsQuery($messageIdsQueryBuilder);
    }

    /**
     * Keyword hits in the conversations of ONE account — the full-text search of {@see findByKeyword()}, filtered on the
     * conversation's `accountId` (part of the query itself, for every caller), {@see DBAIConversationMessage::applyReadRightsQuery}
     * on top. The lexical leg of the cross-conversation hybrid search (fused with the dense + node-summary legs in
     * {@see \DDD\Domain\AI\Services\Conversations\SemanticSearchService}).
     *
     * @return AIConversationMessages|null
     */
    public function findByKeywordAcrossConversations(Account $account, string $query, int $limit = 20): ?AIConversationMessages
    {
        if (trim($query) === '' || !isset($account->id)) {
            return AIConversationMessages::newInstance();
        }
        return $this->findMessagesRankedByMessageIdsQuery(
            $this->buildKeywordSearchMessageIdsQueryBuilderForConversationsOfAccount($account, $query, $limit)
        );
    }

    /**
     * The first query of {@see findByKeywordAcrossConversations()}: the ranked message ids of the keyword search,
     * restricted to the conversations of the account.
     */
    public function buildKeywordSearchMessageIdsQueryBuilderForConversationsOfAccount(Account $account, string $query, int $limit): DoctrineQueryBuilder
    {
        $messageIdsQueryBuilder = $this->buildKeywordSearchMessageIdsQueryBuilder($query, $limit);
        $this->restrictToConversationsOfAccount($messageIdsQueryBuilder, $account);
        return $messageIdsQueryBuilder;
    }

    /**
     * The part every keyword search of this repo shares: a query that returns message IDS alone, best match first.
     * A fulltext query with many matches sorts every matching row; with the `message` text in the sorted row the
     * sort carries megabytes, with the id alone it carries integers. The read rights are part of the query. The
     * caller adds the scope and loads the rows with {@see findMessagesRankedByMessageIdsQuery()}.
     *
     * The two database engines search differently, and DQL has no function both know:
     * - MariaDB: `MATCH(message) AGAINST(:keywordQuery)` in natural-language mode over the FULLTEXT index — a message
     *   matches when it holds any word of the query, words shorter than the server's minimum word length and its
     *   stopwords are not indexed.
     * - PostgreSQL: `FULLTEXT_MATCH` / `FULLTEXT_SCORE` of the framework over the word index of the text. Its query
     *   language requires EVERY word unless the words are joined with `or`, so the query is rewritten to its words
     *   joined with `or` ({@see buildKeywordQueryTextForPostgres()}) — the same "any word" search.
     * The scores of the two engines are not comparable; the order "best match first" is.
     */
    protected function buildKeywordSearchMessageIdsQueryBuilder(string $query, int $limit): DoctrineQueryBuilder
    {
        $alias = self::getBaseModelAlias();
        $messageIdsQueryBuilder = self::createQueryBuilder(true); // true: a scalar select and the joins need the root alias
        if (EntityManagerFactory::getDialect()->capabilities()->isPostgres()) {
            $messageIdsQueryBuilder
                ->select("$alias.id")
                ->andWhere("FULLTEXT_MATCH($alias.message, :keywordQuery, 'DOCUMENT') = true")
                ->addOrderBy("FULLTEXT_SCORE($alias.message, :keywordQuery, 'DOCUMENT')", 'DESC')
                ->setParameter('keywordQuery', static::buildKeywordQueryTextForPostgres($query));
        } else {
            $messageIdsQueryBuilder
                ->select("$alias.id")
                ->andWhere("MATCH($alias.message) AGAINST(:keywordQuery) > 0")
                ->addOrderBy("MATCH($alias.message) AGAINST(:keywordQuery)", 'DESC')
                ->setParameter('keywordQuery', $query);
        }
        $messageIdsQueryBuilder->setMaxResults($limit);
        // find() applies the read rights by itself; a scalar query does not — they are added here (they also exclude
        // truncated messages)
        DBAIConversationMessage::applyReadRightsQuery($messageIdsQueryBuilder);
        return $messageIdsQueryBuilder;
    }

    /**
     * The keyword query as PostgreSQL's web-search query language reads it with the meaning "any of these words":
     * the words of the query (letters, digits and underscores; everything else separates words), each once, joined
     * with `or`. Words shorter than {@see KEYWORD_QUERY_MINIMUM_WORD_LENGTH} characters are left out, as MariaDB's
     * full-text index leaves them out — a search for "is" or "a" would match nearly every message. The word `or`
     * itself is the operator of that language and is left out too. A query without a word left gives the empty
     * text, which matches no message. Pure.
     */
    public static function buildKeywordQueryTextForPostgres(string $query): string
    {
        $queryWords = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $searchedWords = [];
        foreach ($queryWords as $queryWord) {
            if (mb_strlen($queryWord) < self::KEYWORD_QUERY_MINIMUM_WORD_LENGTH || $queryWord === 'or') {
                continue;
            }
            $searchedWords[$queryWord] = $queryWord;
        }
        return implode(' or ', $searchedWords);
    }

    /**
     * Run a query that returns ranked message ids and load the search-hit columns of exactly those rows, in the
     * order of the ranking. Two queries: ids, then rows.
     */
    protected function findMessagesRankedByMessageIdsQuery(DoctrineQueryBuilder $messageIdsQueryBuilder): ?AIConversationMessages
    {
        $rankedMessageIds = array_map('intval', $messageIdsQueryBuilder->getQuery()->getSingleColumnResult());
        if ($rankedMessageIds === []) {
            return AIConversationMessages::newInstance();
        }
        $alias = self::getBaseModelAlias();
        $messagesQueryBuilder = self::createQueryBuilder();
        $messagesQueryBuilder
            ->andWhere("$alias.id IN (:rankedMessageIds)")
            ->setParameter('rankedMessageIds', $rankedMessageIds)
            // every ranked row: without an own limit the default page size of the query options cuts the set
            ->setMaxResults(count($rankedMessageIds));
        return $this->findWithSearchHitColumns($messagesQueryBuilder)?->buildMessagesInOrderOfIds($rankedMessageIds);
    }

    /**
     * Restrict a message query to the conversations whose `accountId` is the account's. This is a filter of the
     * search, not a right: it also holds where the read rights are wider — an account with the admin role (no
     * condition at all), or an account the application lets read the conversations of other accounts (the accounts
     * below it, the accounts it provisioned).
     */
    protected function restrictToConversationsOfAccount(DoctrineQueryBuilder $queryBuilder, Account $account): void
    {
        $conversationAlias = self::getBaseModelAlias() . '_aiConversation_ofAccount';
        $queryBuilder
            ->innerJoin(self::getBaseModelAlias() . '.aiConversation', $conversationAlias)
            ->andWhere("$conversationAlias.accountId = :conversationAccountId")
            ->setParameter('conversationAccountId', (int)$account->id);
    }

    /**
     * Content messages immediately BEFORE an anchor index (NEAREST-anchor first) — the before-half of the
     * `loadMessageContext` window. ONLY user input + assistant prose (`messageType=TEXT`, role∈{USER,ASSISTANT},
     * non-empty, not truncated) — tool-call / signal / empty turns are skipped so the window is clean dialogue.
     * Reduced-column projection ({@see findWithSearchHitColumns}); restricted by the read rights alone; `LIMIT` keeps it
     * cheap (load only what the cap needs). Returns nearest-first (DESC) so the assembler fills outward from the anchor.
     *
     * @return AIConversationMessages|null
     */
    public function findContentMessagesBeforeIndex(int $conversationId, int $anchorIndex, int $limit): ?AIConversationMessages
    {
        return $this->findContentMessagesOnSide($conversationId, $anchorIndex, $limit, true);
    }

    /**
     * Content messages immediately AFTER an anchor index (NEAREST-anchor first) — the after-half of the window. Same
     * content filter + reduced projection + rights as {@see findContentMessagesBeforeIndex}.
     *
     * @return AIConversationMessages|null
     */
    public function findContentMessagesAfterIndex(int $conversationId, int $anchorIndex, int $limit): ?AIConversationMessages
    {
        return $this->findContentMessagesOnSide($conversationId, $anchorIndex, $limit, false);
    }

    /**
     * The highest `supervisorTurnMessageId` of any SUPERVISOR_STEERING signal already materialized on this
     * conversation — the MONOTONIC consumed marker for the single-writer steer materialization: the
     * drive worker appends only supervisor verdicts NEWER than this, so it never re-appends one (idempotent under
     * redelivery) and stays correct even after compaction evicts the older steers from the loaded tail. 0 = none yet.
     */
    public function findLastConsumedSupervisorTurnMessageId(int $conversationId): int
    {
        // A raw scalar aggregate (mirrors {@see DBEntitySet::count()}), NOT a hydrated find(): the consumed marker is
        // an INTERNAL loop read keyed by the trusted conversationId (the one being driven under its run-claim), so it
        // deliberately bypasses applyReadRightsQuery — the rights JOIN's account gate returned 0 rows in the worker's
        // auth context and made every drive re-materialize the same verdicts (steer duplication). No hydration + no
        // entity-registry cache also removes any stale-empty result across the turn's repeated calls.
        $alias = self::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder(true);
        $queryBuilder
            ->select("MAX($alias.supervisorTurnMessageId)")
            ->andWhere("$alias.aiConversationId = :conversationId")
            ->andWhere("$alias.signalSource IN (:supervisorSources)")
            ->setParameter('conversationId', $conversationId)
            ->setParameter('supervisorSources', ConversationSignalSources::getValuesOfSupervisorSignals());
        return (int)($queryBuilder->getQuery()->getSingleScalarResult() ?? 0);
    }

    /** Load ONE message by id with the reduced search-hit projection (id/locus/role/text) + rights — the anchor of a
     *  {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::loadMessageContext} window. */
    public function findContentMessageById(int $id): ?AIConversationMessage
    {
        $alias = self::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder();
        $queryBuilder->andWhere("$alias.id = :id")->setParameter('id', $id)->setMaxResults(1);
        return $this->findWithSearchHitColumns($queryBuilder)?->first();
    }

    /** Shared builder for the two sides — only the index comparison + order direction differ. NEAREST-anchor first. */
    protected function findContentMessagesOnSide(int $conversationId, int $anchorIndex, int $limit, bool $before): ?AIConversationMessages
    {
        if ($limit < 1) {
            return AIConversationMessages::newInstance();
        }
        $alias = self::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.aiConversationId = :conversationId")
            ->andWhere("$alias.messageType = :textType")
            ->andWhere("$alias.role IN (:roles)")
            ->andWhere("$alias.message IS NOT NULL")
            ->andWhere("TRIM($alias.message) != ''")
            ->andWhere("$alias.truncatedAt IS NULL")
            ->andWhere($before ? "$alias.indexInConversation < :anchorIndex" : "$alias.indexInConversation > :anchorIndex")
            ->addOrderBy("$alias.indexInConversation", $before ? 'DESC' : 'ASC')
            ->setParameter('conversationId', $conversationId)
            ->setParameter('textType', AIConversationMessage::MESSAGE_TEXT)
            ->setParameter('roles', [AIConversationMessage::ROLE_USER, AIConversationMessage::ROLE_ASSISTANT])
            ->setParameter('anchorIndex', $anchorIndex)
            ->setMaxResults($limit);
        return $this->findWithSearchHitColumns($queryBuilder);
    }

    /**
     * One page of persisted TEXT messages still missing their embedding ROW (the backfill) — the async
     * dispatch covers new messages, this catches the pre-existing corpus + any dispatch misses. "Un-embedded" is now a
     * clean `LEFT JOIN m.messageEmbedding e ... WHERE e.id IS NULL` (no embedding row exists), NOT a zero-vector
     * comparison — the redesign's whole point. Only non-empty TEXT rows are recall units. Ordered by id so paging is
     * stable. `$afterId` is a keyset cursor: the SYNC backfill omits it (each embedded message gains a row → leaves the
     * no-row set, so a re-query advances), but the ASYNC backfill (dispatch-only) MUST pass it — a dispatched message
     * has no row until the worker runs, so without the `id > $afterId` cursor the next page would re-return (and
     * re-dispatch) the same messages. Pass the highest id of the previous page.
     *
     * @return AIConversationMessages|null
     */
    public function findUnembedded(int $limit = 100, ?int $afterId = null): ?AIConversationMessages
    {
        $alias = self::getBaseModelAlias();
        $queryBuilder = self::createQueryBuilder(true); // true: add SELECT+FROM so the root alias exists before the join (find() detects it + skips re-adding)
        if ($afterId !== null) {
            $queryBuilder->andWhere("$alias.id > :afterId")->setParameter('afterId', $afterId);
        }
        $queryBuilder
            // UN-EMBEDDED = no embedding row (LEFT JOIN the to-many relation → the joined row is NULL). Absence of a row
            // is the indexable "not embedded" marker (the redesign replaced the old zero-vector sentinel).
            ->leftJoin("$alias.messageEmbeddings", 'messageEmbedding')
            ->andWhere('messageEmbedding.id IS NULL')
            ->andWhere("$alias.messageType = :textType")
            // EXCLUDE empty-text rows: {@see AIConversationMessageEmbeddingsService::isEmbeddable} SKIPS a message with
            // empty text (nothing to embed), so an empty-text TEXT row never gains a row — without this filter it is
            // returned by every `findUnembedded` page and the backfill LOOPS on it, never reaching real content rows.
            ->andWhere("$alias.message IS NOT NULL")
            ->andWhere("TRIM($alias.message) != ''")
            ->andWhere("$alias.truncatedAt IS NULL")
            ->addOrderBy("$alias.id", 'ASC')
            ->setParameter('textType', AIConversationMessage::MESSAGE_TEXT)
            ->setMaxResults($limit);
        return $this->find($queryBuilder);
    }

    /**
     * Load search hits through the {@see AIConversationMessage::SEARCH_HIT_LOAD_COLUMNS} partial SELECT:
     * a hit renders only its text + locus, so the heavy columns are never hydrated. The ranked vector lives in the
     * joined embedding row (the COSINE_DISTANCE ORDER BY references `messageEmbedding.embedding` in SQL; it is never
     * selected/hydrated). Cache-bypassed (a partial-hydrated entity must not enter the process-static registry) +
     * snapshot/restore so the projection never leaks into another query in this request.
     */
    protected function findWithSearchHitColumns(DoctrineQueryBuilder $queryBuilder): ?AIConversationMessages
    {
        AIConversationMessages::setDefaultQueryOptionsSnapshot();
        try {
            // setSelect() takes the SelectOptions BY REFERENCE — assign to a variable first.
            $select = SelectOptions::fromString(implode(',', AIConversationMessage::SEARCH_HIT_LOAD_COLUMNS));
            AIConversationMessages::getDefaultQueryOptions()->setSelect($select, false);
            return $this->find($queryBuilder, false);
        } finally {
            AIConversationMessages::restoreDefaultQueryOptionsSnapshot();
        }
    }
}