<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionBudgets;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBConversationCompactionNode;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBConversationCompactionNodes;
use DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * EntitiesService for {@see AIConversationCompactionNode}. Owns the repo-touching half of the
 * compaction tree: loading a conversation's whole node set (one indexed query) so the PURE cover/roll-up planning on
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation} can run in-memory — the same path for the persisted and
 * the ephemeral/eval conversation (the integration constraint: the cover must NOT be DB-only, since the eval suite
 * drives ephemeral conversations with no rows). The roll-up EXECUTION (summarizer call + INSERT cascade) lives in
 * {@see AIConversationCompactionService}; this service is the loader + persistence seam.
 *
 * @method AIConversationCompactionNode find(string|int|null $entityId, bool $useEntityRegistrCache = true)
 * @method AIConversationCompactionNodes findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBConversationCompactionNode getEntityRepoClassInstance()
 * @method DBConversationCompactionNodes getEntitySetRepoClassInstance()
 */
class ConversationCompactionNodesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AIConversationCompactionNode::class;

    /**
     * Upper bound of {@see self::findAllNodesForConversation()} — the whole tree of one conversation. A set query
     * WITHOUT an explicit maxResults gets the default QueryOptions top (50) injected
     * ({@see \DDD\Domain\Base\Repo\DB\DBEntitySet::applyQueryOptions()} applies it only when the query builder carries
     * no maxResults), so the "whole tree" silently ended after 50 nodes. Sizing: a tree of L leaves rolled up with
     * fan-in K holds L × (1 + 1/K + 1/K² …) ≈ L × K/(K−1) nodes — 1.25 L at the token-budget fan-in of 5, 1.34 L at
     * the degenerate fan-in of 4. Even one leaf per message over the 5,000-message context cap
     * ({@see AIConversationMessagesService::CONTEXT_MESSAGE_WINDOW} × {@see AIConversationMessagesService::MAX_CONTEXT_WINDOWS})
     * stays below 6,700 nodes; twice that cap is the headroom, while a pathological tree still never loads unbounded.
     */
    public const int MAX_NODES_PER_CONVERSATION = AIConversationMessagesService::CONTEXT_MESSAGE_WINDOW * AIConversationMessagesService::MAX_CONTEXT_WINDOWS * 2;

    /**
     * "Search within compactions": embed the query text (in the SERVICE) and rank this conversation's
     * compaction nodes — ALL tree levels — by cosine distance, so a conceptual query hits a node at its resolution
     * (a coarse L2 for an early theme, a fine L0 for a specific segment). The parallel message-leaf search
     * (the message search of {@see SemanticSearchService}) is fused by the caller. Vector-only for now.
     * Returns empty when the conversation is unpersisted or the query is blank.
     */
    public function findSimilarNodesByText(AIConversation $conversation, string $queryText, int $limit = 5): AIConversationCompactionNodes
    {
        if (!isset($conversation->id) || trim($queryText) === '') {
            return AIConversationCompactionNodes::newInstance();
        }
        /** @var AITextEmbeddingsService $embeddingsService */
        $embeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
        $queryEmbedding = $embeddingsService->generateEmbeddingForText($queryText);
        return $this->getEntitySetRepoClassInstance()->findSimilarByEmbedding($queryEmbedding, (int)$conversation->id, $limit)
            ?? AIConversationCompactionNodes::newInstance();
    }

    /**
     * Load ALL compaction nodes of a conversation in one indexed query, ordered by covered range then level — the
     * input the in-memory greedy cover + roll-up planning on AIConversation consume. The point-in-time (`asOf`)
     * filter is applied IN-MEMORY by the planning code (created <= T), so this loader is asOf-agnostic and its
     * result is reusable across both the live and the forensic reconstruction read.
     *
     * @param AIConversation $aIConversation
     * @return AIConversationCompactionNodes|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAllNodesForConversation(AIConversation $aIConversation): ?AIConversationCompactionNodes
    {
        $aiConversationId = (int)($aIConversation->id ?? 0);
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->setParameter('aiConversationId', $aiConversationId)
            ->orderBy("$baseModelAlias.coveredFromMessageIndex", 'ASC')
            ->addOrderBy("$baseModelAlias.level", 'DESC')
            ->addOrderBy("$baseModelAlias.coveredToMessageIndex", 'DESC')
            // EXPLICIT maxResults — the default QueryOptions top (50) would cut the tree (see the constant)
            ->setMaxResults(self::MAX_NODES_PER_CONVERSATION);
        return $repoClass->find($queryBuilder);
    }

    /**
     * The recency-graded cover ONLY — the O(log N) range-cover read (the endless-scaling read). Walks
     * the implicit segment tree one indexed lookup per staircase step instead of loading the whole node set: starting at
     * message-index 0, take the COARSEST node that starts exactly there (`coveredFromMessageIndex = pos`, then highest
     * level, then longest reach), jump past its end, repeat — until no node starts at the next position (the prefix
     * edge). Returns ~`depth × retention` ≈ log_K(N) nodes oldest→newest — the exact tiling
     * {@see AIConversation::buildCompactionCover} produces in-memory over the FULL set, but without loading it (the
     * cover-of-the-cover is the cover, so every downstream in-memory cover/summary method reproduces the identical
     * result over just these nodes). `$asOf` filters `created <= $asOf` per step for the point-in-time read; null = now.
     *
     * Exact-start (`= pos`) is byte-identical to the gap-safe (`<= pos <=`) in-memory greedy on any committed
     * append-only tree (every covered position is covered by a node starting at an exact segment boundary at the finest
     * level; roll-up boundaries are nested/aligned) — a test of the application proves it. Served by the
     * existing index (aiConversationId, coveredFromMessageIndex, created); the ≤depth rows per step are level-sorted
     * in-memory.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCoverNodesForConversation(AIConversation $aIConversation, ?DateTime $asOf = null): AIConversationCompactionNodes
    {
        $aiConversationId = (int)($aIConversation->id ?? 0);
        $repoClass = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $coverNodes = AIConversationCompactionNodes::newInstance();
        $position = 0;
        while (true) {
            $queryBuilder = $repoClass::createQueryBuilder(true);
            $queryBuilder
                ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
                ->andWhere("$baseModelAlias.coveredFromMessageIndex = :position")
                ->setParameter('aiConversationId', $aiConversationId)
                ->setParameter('position', $position)
                ->orderBy("$baseModelAlias.level", 'DESC')
                ->addOrderBy("$baseModelAlias.coveredToMessageIndex", 'DESC')
                ->setMaxResults(1);
            if ($asOf !== null) {
                $queryBuilder
                    ->andWhere("$baseModelAlias.created <= :asOf")
                    ->setParameter('asOf', $asOf);
            }
            $stepResultPage = $repoClass->find($queryBuilder);
            $coverNode = $stepResultPage->getElements()[0] ?? null;
            if (!$coverNode instanceof AIConversationCompactionNode || $coverNode->coveredToMessageIndex === null) {
                break; // the prefix edge — no node starts at $position; everything beyond is the raw verbatim tail
            }
            // Read the next position BEFORE add(): ObjectSet::add() takes the element BY-REF (variadic), which widens
            // $coverNode back to ?BaseObject for PHPStan — so capture the index first.
            $position = $coverNode->coveredToMessageIndex + 1;
            $nodeToAdd = $coverNode;
            $coverNodes->add($nodeToAdd);
        }
        return $coverNodes;
    }

    /**
     * The EXPOSED FRONTIER only — the ~log_K(N) nodes the compaction round actually needs, instead of
     * loading ALL M ≈ Θ(N) nodes just to compute exposedness in PHP. A node is exposed ⟺ no higher-level node covers
     * it. Because the oldest-first hole-free roll-up makes every higher level cover a clean PREFIX, "exposed at level L"
     * = "level-L nodes whose coveredFrom is past the prefix the higher levels reach". So walk levels TOP-DOWN, carrying
     * `higherEdge` = the max coveredTo of all higher levels (which falls out of the already-loaded exposed nodes —
     * covered nodes sit at coveredTo ≤ higherEdge, exposed ones at coveredTo > it): each level is ONE indexed query
     * `level = L AND coveredFrom > higherEdge`, served by the UNIQUE(aiConversationId, level, coveredFromMessageIndex,
     * coveredToMessageIndex) index. ~log_K(N) nodes total, no GROUP BY, no window function, no raw SQL. Byte-identical
     * to {@see AIConversationCompactionNodes::buildExposedNodesByLevel()} over the full set (the higher tiling aligns to
     * level-L boundaries, so no node straddles the edge) — RollUpFrontierIncrementalEqualsRecomputeTest +
     * FrontierLoadTopDownEqualsFullScanTest lock it.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findExposedNodesForConversation(AIConversation $aIConversation): AIConversationCompactionNodes
    {
        $aiConversationId = (int)($aIConversation->id ?? 0);
        $repoClass = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $frontierNodes = AIConversationCompactionNodes::newInstance();
        // Exclusive lower bound on coveredFrom for an exposed node: the max coveredTo of the levels ABOVE the current.
        // -1 at the top (nothing is above the highest level, so all its nodes are exposed).
        $higherEdge = -1;
        for ($level = CompactionBudgets::MAX_TREE_LEVELS - 1; $level >= 0; $level--) {
            $queryBuilder = $repoClass::createQueryBuilder(true);
            $queryBuilder
                ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
                ->andWhere("$baseModelAlias.level = :level")
                ->andWhere("$baseModelAlias.coveredFromMessageIndex > :higherEdge")
                ->setParameter('aiConversationId', $aiConversationId)
                ->setParameter('level', $level)
                ->setParameter('higherEdge', $higherEdge)
                ->orderBy("$baseModelAlias.coveredFromMessageIndex", 'ASC');
            $exposedAtLevel = $repoClass->find($queryBuilder);
            foreach ($exposedAtLevel->getElements() as $exposedNode) {
                $exposedToIndex = $exposedNode->coveredToMessageIndex; // capture BEFORE the by-ref add() widens the type
                $nodeToAdd = $exposedNode;
                $frontierNodes->add($nodeToAdd);
                if ($exposedToIndex !== null && $exposedToIndex > $higherEdge) {
                    $higherEdge = $exposedToIndex;
                }
            }
        }
        return $frontierNodes;
    }
}
