<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Search;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBConversationCompactionNodes;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The vector search over the summaries of the compaction nodes
 * ({@see DBConversationCompactionNodes::findSimilarByEmbedding()},
 * {@see DBConversationCompactionNodes::findSimilarAcrossConversationsByEmbedding()}) against the database, on both
 * engines: the nearest node comes first, a node holding the zero vector is no candidate, and the search never
 * leaves its scope (one conversation, the conversations of one account).
 *
 * The stored vectors point along the first two axes and the search vector is the first axis, as in
 * {@see AIConversationMessagesVectorSearchDatabaseTest}.
 */
class ConversationCompactionNodesVectorSearchDatabaseTest extends DatabaseTestCase
{
    protected const int SEARCHING_ACCOUNT_ID = 10;
    protected const int OTHER_ACCOUNT_ID = 20;

    protected const int FIRST_CONVERSATION_ID = 1;
    protected const int SECOND_CONVERSATION_ID = 2;
    protected const int CONVERSATION_OF_OTHER_ACCOUNT_ID = 3;

    protected const int FARTHEST_NODE_ID = 201;
    protected const int NEAREST_NODE_ID = 202;
    protected const int ZERO_VECTOR_NODE_ID = 203;
    protected const int SECOND_CONVERSATION_NODE_ID = 211;
    protected const int OTHER_ACCOUNT_NODE_ID = 221;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::SEARCHING_ACCOUNT_ID, 'email' => 'owner@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'other-owner@example.test']);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::FIRST_CONVERSATION_ID, 'accountId' => self::SEARCHING_ACCOUNT_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::SECOND_CONVERSATION_ID, 'accountId' => self::SEARCHING_ACCOUNT_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_OF_OTHER_ACCOUNT_ID, 'accountId' => self::OTHER_ACCOUNT_ID]);

        $this->insertNode(self::FARTHEST_NODE_ID, self::FIRST_CONVERSATION_ID, 0, [0.0, 1.0]);
        $this->insertNode(self::NEAREST_NODE_ID, self::FIRST_CONVERSATION_ID, 10, [1.0, 0.0]);
        // a node inserted without a vector holds the zero vector, the default of the column
        $this->insertNode(self::ZERO_VECTOR_NODE_ID, self::FIRST_CONVERSATION_ID, 20, null);
        $this->insertNode(self::SECOND_CONVERSATION_NODE_ID, self::SECOND_CONVERSATION_ID, 0, [1.0, 1.0]);
        $this->insertNode(self::OTHER_ACCOUNT_NODE_ID, self::CONVERSATION_OF_OTHER_ACCOUNT_ID, 0, [1.0, 0.0]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheNearestNodeOfTheConversationComesFirst(): void
    {
        $foundNodes = (new DBConversationCompactionNodes())->findSimilarByEmbedding($this->buildSearchEmbedding(), self::FIRST_CONVERSATION_ID, 10);

        self::assertSame(
            [self::NEAREST_NODE_ID, self::FARTHEST_NODE_ID],
            $this->getNodeIdsInOrder($foundNodes),
            'nearest first; the node holding the zero vector is no candidate'
        );
    }

    public function testTheFoundNodeCarriesTheMessageRangeItCovers(): void
    {
        $foundNodes = (new DBConversationCompactionNodes())->findSimilarByEmbedding($this->buildSearchEmbedding(), self::FIRST_CONVERSATION_ID, 1);

        $nearestNode = ($foundNodes?->getElements() ?? [])[0] ?? null;
        self::assertNotNull($nearestNode);
        self::assertSame(
            [self::NEAREST_NODE_ID, self::FIRST_CONVERSATION_ID, 10, 19],
            [(int)$nearestNode->id, (int)$nearestNode->aiConversationId, (int)$nearestNode->coveredFromMessageIndex, (int)$nearestNode->coveredToMessageIndex]
        );
    }

    public function testTheSearchAcrossConversationsReadsTheConversationsOfTheAccountOnly(): void
    {
        $searchingAccount = new Account();
        $searchingAccount->id = self::SEARCHING_ACCOUNT_ID;

        $foundNodes = (new DBConversationCompactionNodes())->findSimilarAcrossConversationsByEmbedding($searchingAccount, $this->buildSearchEmbedding(), 10);

        self::assertSame(
            [self::NEAREST_NODE_ID, self::SECOND_CONVERSATION_NODE_ID, self::FARTHEST_NODE_ID],
            $this->getNodeIdsInOrder($foundNodes),
            'both conversations of the account in one order of distance, never the conversation of the other account'
        );
    }

    /** The search vector: the first axis. */
    protected function buildSearchEmbedding(): TextEmbedding
    {
        return new TextEmbedding($this->buildVectorValues([1.0, 0.0]));
    }

    /**
     * @param float[] $firstValues the values of the first axes; every other axis holds 0
     * @return float[] a vector of the stored dimension
     */
    protected function buildVectorValues(array $firstValues): array
    {
        return array_replace(array_fill(0, Vector::DIMENSION_OPENAI_EMBEDDING_SMALL, 0.0), $firstValues);
    }

    /** @param float[]|null $firstVectorValues the values of the first axes of the node's stored vector; null: no vector is written */
    protected function insertNode(int $nodeId, int $conversationId, int $coveredFromMessageIndex, ?array $firstVectorValues): void
    {
        $this->insertRow(DBAIConversationCompactionNodeModel::getTableName(), [
            'id' => $nodeId,
            'aiConversationId' => $conversationId,
            'level' => 0,
            'coveredFromMessageIndex' => $coveredFromMessageIndex,
            'coveredToMessageIndex' => $coveredFromMessageIndex + 9,
            'summaryText' => 'summary of node ' . $nodeId,
            'fromRawMessages' => 0,
        ]);
        if ($firstVectorValues === null) {
            return;
        }
        EntityManagerFactory::getInstance()->createQuery(
            'UPDATE ' . DBAIConversationCompactionNodeModel::class . ' compactionNode SET compactionNode.summaryEmbedding = VEC_FROM_TEXT(:vectorText)'
            . ' WHERE compactionNode.id = :nodeId'
        )->setParameters([
            'vectorText' => json_encode($this->buildVectorValues($firstVectorValues), JSON_THROW_ON_ERROR),
            'nodeId' => $nodeId,
        ])->execute();
    }

    /** @return int[] The ids of the found nodes in the order the search returned them */
    protected function getNodeIdsInOrder(?AIConversationCompactionNodes $foundNodes): array
    {
        $nodeIds = [];
        foreach ($foundNodes?->getElements() ?? [] as $foundNode) {
            $nodeIds[] = (int)$foundNode->id;
        }
        return $nodeIds;
    }
}
