<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Search;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddingModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The vector search of the message repo ({@see DBAIConversationMessages::findSimilarByEmbedding()},
 * {@see DBAIConversationMessages::findSimilarAcrossConversationsByEmbedding()}) against the database, on both
 * engines: the nearest message comes first, a message without an embedding row or with the zero vector is no
 * candidate, and the search never leaves its scope (one conversation with an optional index range, the
 * conversations of one account).
 *
 * The stored vectors point along the first two axes: the search vector is the first axis, so the distance of a
 * message is read off its vector — the first axis itself is nearest, a vector between the axes follows, the second
 * axis is farthest.
 */
class AIConversationMessagesVectorSearchDatabaseTest extends DatabaseTestCase
{
    protected const int SEARCHING_ACCOUNT_ID = 10;
    protected const int OTHER_ACCOUNT_ID = 20;

    protected const int FIRST_CONVERSATION_ID = 1;
    protected const int SECOND_CONVERSATION_ID = 2;
    protected const int CONVERSATION_OF_OTHER_ACCOUNT_ID = 3;

    protected const int FARTHEST_MESSAGE_ID = 101;
    protected const int NEAREST_MESSAGE_ID = 102;
    protected const int MIDDLE_MESSAGE_ID = 103;
    protected const int ZERO_VECTOR_MESSAGE_ID = 104;
    protected const int MESSAGE_WITHOUT_EMBEDDING_ROW_ID = 105;
    protected const int SECOND_CONVERSATION_MESSAGE_ID = 201;
    protected const int OTHER_ACCOUNT_MESSAGE_ID = 301;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::SEARCHING_ACCOUNT_ID, 'email' => 'owner@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'other-owner@example.test']);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::FIRST_CONVERSATION_ID, 'accountId' => self::SEARCHING_ACCOUNT_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::SECOND_CONVERSATION_ID, 'accountId' => self::SEARCHING_ACCOUNT_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_OF_OTHER_ACCOUNT_ID, 'accountId' => self::OTHER_ACCOUNT_ID]);

        $this->insertMessageWithVector(self::FARTHEST_MESSAGE_ID, self::FIRST_CONVERSATION_ID, 0, [0.0, 1.0]);
        $this->insertMessageWithVector(self::NEAREST_MESSAGE_ID, self::FIRST_CONVERSATION_ID, 1, [1.0, 0.0]);
        $this->insertMessageWithVector(self::MIDDLE_MESSAGE_ID, self::FIRST_CONVERSATION_ID, 2, [1.0, 1.0]);
        // a row inserted without a vector holds the zero vector, the default of the column
        $this->insertMessage(self::ZERO_VECTOR_MESSAGE_ID, self::FIRST_CONVERSATION_ID, 3);
        $this->insertRow(DBAIConversationMessageEmbeddingModel::getTableName(), ['aiConversationMessageId' => self::ZERO_VECTOR_MESSAGE_ID]);
        $this->insertMessage(self::MESSAGE_WITHOUT_EMBEDDING_ROW_ID, self::FIRST_CONVERSATION_ID, 4);
        $this->insertMessageWithVector(self::SECOND_CONVERSATION_MESSAGE_ID, self::SECOND_CONVERSATION_ID, 0, [1.0, 0.2]);
        $this->insertMessageWithVector(self::OTHER_ACCOUNT_MESSAGE_ID, self::CONVERSATION_OF_OTHER_ACCOUNT_ID, 0, [1.0, 0.0]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheNearestMessageOfTheConversationComesFirst(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findSimilarByEmbedding($this->buildSearchEmbedding(), self::FIRST_CONVERSATION_ID, 10);

        self::assertSame(
            [self::NEAREST_MESSAGE_ID, self::MIDDLE_MESSAGE_ID, self::FARTHEST_MESSAGE_ID],
            $this->getMessageIdsInOrder($foundMessages),
            'nearest first; the message holding the zero vector and the message without an embedding row are no candidates'
        );
    }

    public function testTheLimitKeepsTheNearestMessages(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findSimilarByEmbedding($this->buildSearchEmbedding(), self::FIRST_CONVERSATION_ID, 2);

        self::assertSame([self::NEAREST_MESSAGE_ID, self::MIDDLE_MESSAGE_ID], $this->getMessageIdsInOrder($foundMessages));
    }

    public function testTheIndexRangeLimitsTheSearchToAPartOfTheConversation(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findSimilarByEmbedding($this->buildSearchEmbedding(), self::FIRST_CONVERSATION_ID, 10, 2, 4);

        self::assertSame([self::MIDDLE_MESSAGE_ID], $this->getMessageIdsInOrder($foundMessages));
    }

    public function testTheSearchAcrossConversationsReadsTheConversationsOfTheAccountOnly(): void
    {
        $searchingAccount = new Account();
        $searchingAccount->id = self::SEARCHING_ACCOUNT_ID;

        $foundMessages = (new DBAIConversationMessages())->findSimilarAcrossConversationsByEmbedding($searchingAccount, $this->buildSearchEmbedding(), 10);

        self::assertSame(
            [self::NEAREST_MESSAGE_ID, self::SECOND_CONVERSATION_MESSAGE_ID, self::MIDDLE_MESSAGE_ID, self::FARTHEST_MESSAGE_ID],
            $this->getMessageIdsInOrder($foundMessages),
            'both conversations of the account in one order of distance, never the conversation of the other account'
        );
    }

    public function testAnEmptySearchEmbeddingFindsNothing(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findSimilarByEmbedding(new TextEmbedding([]), self::FIRST_CONVERSATION_ID, 10);

        self::assertSame([], $this->getMessageIdsInOrder($foundMessages));
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

    protected function insertMessage(int $messageId, int $conversationId, int $indexInConversation): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'role' => AIConversationMessage::ROLE_USER,
            'authorType' => AIConversationMessage::AUTHOR_USER,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'message' => 'message ' . $messageId,
        ]);
    }

    /** @param float[] $firstVectorValues the values of the first axes of the message's stored vector */
    protected function insertMessageWithVector(int $messageId, int $conversationId, int $indexInConversation, array $firstVectorValues): void
    {
        $this->insertMessage($messageId, $conversationId, $indexInConversation);
        $this->insertRow(DBAIConversationMessageEmbeddingModel::getTableName(), ['aiConversationMessageId' => $messageId]);
        EntityManagerFactory::getInstance()->createQuery(
            'UPDATE ' . DBAIConversationMessageEmbeddingModel::class . ' embeddingRow SET embeddingRow.embedding = VEC_FROM_TEXT(:vectorText)'
            . ' WHERE embeddingRow.aiConversationMessageId = :messageId'
        )->setParameters([
            'vectorText' => json_encode($this->buildVectorValues($firstVectorValues), JSON_THROW_ON_ERROR),
            'messageId' => $messageId,
        ])->execute();
    }

    /** @return int[] The ids of the found messages in the order the search returned them */
    protected function getMessageIdsInOrder(?AIConversationMessages $foundMessages): array
    {
        $messageIds = [];
        foreach ($foundMessages?->getElements() ?? [] as $foundMessage) {
            $messageIds[] = (int)$foundMessage->id;
        }
        return $messageIds;
    }
}
