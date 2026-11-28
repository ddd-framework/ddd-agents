<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Search;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddingModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\AI\Services\Conversations\SemanticSearchService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use ReflectionMethod;

/**
 * The scope of the search across conversations with searchable agent spaces: the acting account's conversations OR
 * the conversations of the given spaces (any account), on both engines. Of another account's conversation only the
 * dialogue counts; a space outside the list stays out; without spaces the search is the account's alone.
 */
class SearchAcrossReadableAgentSpacesDatabaseTest extends DatabaseTestCase
{
    protected const int ACTING_ACCOUNT_ID = 10;
    protected const int OTHER_ACCOUNT_ID = 20;
    protected const int OWN_SPACE_ID = 50;
    protected const int SEARCHABLE_SPACE_ID = 60;
    protected const int OTHER_SPACE_ID = 70;

    protected const int OWN_CONVERSATION_ID = 1;
    protected const int OTHER_ACCOUNT_IN_SEARCHABLE_SPACE_CONVERSATION_ID = 2;
    protected const int OTHER_ACCOUNT_IN_OTHER_SPACE_CONVERSATION_ID = 3;
    protected const int OTHER_ACCOUNT_EVAL_RUN_IN_SEARCHABLE_SPACE_CONVERSATION_ID = 4;

    /** The tool message (assistant role) of another account's conversation in the searchable space: no dialogue, never a hit */
    protected const int TOOL_MESSAGE_ID = 250;

    /** A platform text (system role) of the acting account's own conversation: found in the own conversations */
    protected const int OWN_PLATFORM_TEXT_MESSAGE_ID = 160;

    /** A platform text (system role) of another account's conversation in the searchable space: no dialogue, never a hit */
    protected const int OTHER_ACCOUNT_PLATFORM_TEXT_MESSAGE_ID = 260;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACTING_ACCOUNT_ID, 'email' => 'acting@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'other@example.test']);
        $this->insertAgentSpaceRow(['id' => self::OWN_SPACE_ID, 'accountId' => self::ACTING_ACCOUNT_ID]);
        $this->insertAgentSpaceRow(['id' => self::SEARCHABLE_SPACE_ID, 'accountId' => self::OTHER_ACCOUNT_ID]);
        $this->insertAgentSpaceRow(['id' => self::OTHER_SPACE_ID, 'accountId' => self::OTHER_ACCOUNT_ID]);
        $this->insertConversation(self::OWN_CONVERSATION_ID, self::ACTING_ACCOUNT_ID, self::OWN_SPACE_ID);
        $this->insertConversation(self::OTHER_ACCOUNT_IN_SEARCHABLE_SPACE_CONVERSATION_ID, self::OTHER_ACCOUNT_ID, self::SEARCHABLE_SPACE_ID);
        $this->insertConversation(self::OTHER_ACCOUNT_IN_OTHER_SPACE_CONVERSATION_ID, self::OTHER_ACCOUNT_ID, self::OTHER_SPACE_ID);
        $this->insertConversation(
            self::OTHER_ACCOUNT_EVAL_RUN_IN_SEARCHABLE_SPACE_CONVERSATION_ID,
            self::OTHER_ACCOUNT_ID,
            self::SEARCHABLE_SPACE_ID,
            ['runSettings' => '{"evalRun": true}']
        );
        foreach ([1, 2, 3, 4] as $conversationId) {
            $this->insertMessageWithVector($conversationId * 100, $conversationId);
        }
        $this->insertMessageWithVector(self::TOOL_MESSAGE_ID, self::OTHER_ACCOUNT_IN_SEARCHABLE_SPACE_CONVERSATION_ID, AIConversationMessage::ROLE_ASSISTANT, 1, false);
        $this->insertMessageWithVector(self::OWN_PLATFORM_TEXT_MESSAGE_ID, self::OWN_CONVERSATION_ID, AIConversationMessage::ROLE_SYSTEM, 2);
        $this->insertMessageWithVector(
            self::OTHER_ACCOUNT_PLATFORM_TEXT_MESSAGE_ID,
            self::OTHER_ACCOUNT_IN_SEARCHABLE_SPACE_CONVERSATION_ID,
            AIConversationMessage::ROLE_SYSTEM,
            2
        );
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testWithoutSearchableSpacesTheVectorAndKeywordSearchAnswerFromTheAccountAlone(): void
    {
        $messagesRepository = new DBAIConversationMessages();

        self::assertSame([100, self::OWN_PLATFORM_TEXT_MESSAGE_ID], $this->getSortedMessageIds(
            $messagesRepository->findSimilarAcrossConversationsByEmbedding($this->buildAccount(), new TextEmbedding($this->buildVectorValues()), 50)
        ));
        self::assertSame(
            [100, self::OWN_PLATFORM_TEXT_MESSAGE_ID],
            $this->getSortedMessageIds($messagesRepository->findByKeywordAcrossConversations($this->buildAccount(), 'bicycle', 50))
        );
    }

    public function testWithASearchableSpaceTheDialogueOfAnotherAccountInThatSpaceIsFound(): void
    {
        $messagesRepository = new DBAIConversationMessages();
        $searchableAgentSpaceIds = [self::SEARCHABLE_SPACE_ID];

        self::assertSame([100, self::OWN_PLATFORM_TEXT_MESSAGE_ID, 200], $this->getSortedMessageIds($messagesRepository->findSimilarAcrossConversationsByEmbedding(
            $this->buildAccount(),
            new TextEmbedding($this->buildVectorValues()),
            50,
            $searchableAgentSpaceIds
        )));
        self::assertSame([100, self::OWN_PLATFORM_TEXT_MESSAGE_ID, 200], $this->getSortedMessageIds(
            $messagesRepository->findByKeywordAcrossConversations($this->buildAccount(), 'bicycle', 50, $searchableAgentSpaceIds)
        ));
    }

    public function testTheCandidatesAreTheAccountsAndTheSearchableSpacesConversations(): void
    {
        $conversationsRepository = new DBAIConversations();

        self::assertSame([self::OWN_CONVERSATION_ID], $this->getSortedConversationIds(
            $conversationsRepository->findRecallCandidatesOfAccount(self::ACTING_ACCOUNT_ID, [], null, null, null, 20)
        ));
        self::assertSame([self::OWN_CONVERSATION_ID, self::OTHER_ACCOUNT_IN_SEARCHABLE_SPACE_CONVERSATION_ID], $this->getSortedConversationIds(
            $conversationsRepository->findRecallCandidatesOfAccount(self::ACTING_ACCOUNT_ID, [], null, null, null, 20, [self::SEARCHABLE_SPACE_ID])
        ));
    }

    public function testAConversationIsReadThroughTheScopeOnlyInsideIt(): void
    {
        $conversationsRepository = new DBAIConversations();
        $searchableAgentSpaceIds = [self::SEARCHABLE_SPACE_ID];

        self::assertInstanceOf(AIConversation::class, $conversationsRepository->findConversationInSearchScope(
            self::OTHER_ACCOUNT_IN_SEARCHABLE_SPACE_CONVERSATION_ID,
            self::ACTING_ACCOUNT_ID,
            $searchableAgentSpaceIds
        ));
        self::assertNull($conversationsRepository->findConversationInSearchScope(
            self::OTHER_ACCOUNT_IN_OTHER_SPACE_CONVERSATION_ID,
            self::ACTING_ACCOUNT_ID,
            $searchableAgentSpaceIds
        ), 'a space outside the list');
        self::assertNull($conversationsRepository->findConversationInSearchScope(
            self::OTHER_ACCOUNT_EVAL_RUN_IN_SEARCHABLE_SPACE_CONVERSATION_ID,
            self::ACTING_ACCOUNT_ID,
            $searchableAgentSpaceIds
        ), 'an eval run is no conversation the search answers from');
        self::assertNull($conversationsRepository->findConversationInSearchScope(
            self::OTHER_ACCOUNT_IN_SEARCHABLE_SPACE_CONVERSATION_ID,
            self::ACTING_ACCOUNT_ID,
            []
        ), 'without spaces the account alone');
    }

    public function testACompactionNodeOfAnotherAccountExpandsToItsDialogueOnly(): void
    {
        $this->insertNode(900, self::OWN_CONVERSATION_ID);
        $this->insertNode(901, self::OTHER_ACCOUNT_IN_SEARCHABLE_SPACE_CONVERSATION_ID);
        $this->insertNode(902, self::OTHER_ACCOUNT_IN_OTHER_SPACE_CONVERSATION_ID);
        /** @var SemanticSearchService $semanticSearchService */
        $semanticSearchService = DDDService::instance()->getService(SemanticSearchService::class);
        $nodeLeg = new ReflectionMethod($semanticSearchService, 'expandNodeRegionsToMessagesAcrossConversations');

        $expandedMessageIds = $this->getSortedMessageIdsOfList($nodeLeg->invoke(
            $semanticSearchService,
            $this->buildAccount(),
            new TextEmbedding($this->buildVectorValues()),
            50,
            [self::SEARCHABLE_SPACE_ID]
        ));

        self::assertSame([100, self::OWN_PLATFORM_TEXT_MESSAGE_ID, 200], $expandedMessageIds);
    }

    public function testThePositionOfAMessageIsItsConversationAndIndex(): void
    {
        $messagePosition = (new DBAIConversationMessages())->findPositionOfMessageIgnoringRights(self::TOOL_MESSAGE_ID);
        self::assertNotNull($messagePosition);
        self::assertSame(self::OTHER_ACCOUNT_IN_SEARCHABLE_SPACE_CONVERSATION_ID, $messagePosition->aiConversationId);
        self::assertSame(1, $messagePosition->indexInConversation);
        self::assertNull((new DBAIConversationMessages())->findPositionOfMessageIgnoringRights(999999));
    }

    protected function buildAccount(): Account
    {
        $account = new Account();
        $account->id = self::ACTING_ACCOUNT_ID;
        return $account;
    }

    /** @param array<string, int|string> $columns */
    protected function insertConversation(int $conversationId, int $accountId, int $agentSpaceId, array $columns = []): void
    {
        $this->insertRow(
            DBAIConversationModel::getTableName(),
            ['id' => $conversationId, 'accountId' => $accountId, 'agentSpaceId' => $agentSpaceId] + $columns
        );
    }

    /** @return float[] a vector of the stored dimension along the first axis */
    protected function buildVectorValues(): array
    {
        return array_replace(array_fill(0, Vector::DIMENSION_OPENAI_EMBEDDING_SMALL, 0.0), [1.0]);
    }

    protected function insertMessageWithVector(int $messageId, int $conversationId, string $role = AIConversationMessage::ROLE_USER, int $indexInConversation = 0, bool $isDialogue = true): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'role' => $role,
            'authorType' => AIConversationMessage::AUTHOR_USER,
            'messageType' => $isDialogue ? AIConversationMessage::MESSAGE_TEXT : AIConversationMessage::MESSAGE_TOOL_MESSAGE,
            'message' => 'my bicycle tour number ' . $messageId,
        ]);
        $this->insertRow(DBAIConversationMessageEmbeddingModel::getTableName(), ['aiConversationMessageId' => $messageId]);
        EntityManagerFactory::getInstance()->createQuery(
            'UPDATE ' . DBAIConversationMessageEmbeddingModel::class . ' embeddingRow SET embeddingRow.embedding = VEC_FROM_TEXT(:vectorText)'
            . ' WHERE embeddingRow.aiConversationMessageId = :messageId'
        )->setParameters([
            'vectorText' => json_encode($this->buildVectorValues(), JSON_THROW_ON_ERROR),
            'messageId' => $messageId,
        ])->execute();
    }

    /** @return int[] The ids of the found messages, ascending */
    protected function getSortedMessageIds(?AIConversationMessages $foundMessages): array
    {
        $messageIds = [];
        foreach ($foundMessages?->getElements() ?? [] as $foundMessage) {
            $messageIds[] = (int)$foundMessage->id;
        }
        sort($messageIds);
        return $messageIds;
    }

    /**
     * @param AIConversationMessage[] $foundMessages
     * @return int[] The ids of the messages, ascending
     */
    protected function getSortedMessageIdsOfList(array $foundMessages): array
    {
        $messageIds = [];
        foreach ($foundMessages as $foundMessage) {
            $messageIds[] = (int)$foundMessage->id;
        }
        sort($messageIds);
        return $messageIds;
    }

    /** A compaction node over the messages 0 to 9 of `$conversationId`, its summary vector along the first axis */
    protected function insertNode(int $nodeId, int $conversationId): void
    {
        $this->insertRow(DBAIConversationCompactionNodeModel::getTableName(), [
            'id' => $nodeId,
            'aiConversationId' => $conversationId,
            'level' => 0,
            'coveredFromMessageIndex' => 0,
            'coveredToMessageIndex' => 9,
            'summaryText' => 'summary of node ' . $nodeId,
            'fromRawMessages' => 0,
        ]);
        EntityManagerFactory::getInstance()->createQuery(
            'UPDATE ' . DBAIConversationCompactionNodeModel::class . ' compactionNode SET compactionNode.summaryEmbedding = VEC_FROM_TEXT(:vectorText)'
            . ' WHERE compactionNode.id = :nodeId'
        )->setParameters([
            'vectorText' => json_encode($this->buildVectorValues(), JSON_THROW_ON_ERROR),
            'nodeId' => $nodeId,
        ])->execute();
    }

    /** @return int[] The ids of the found conversations, ascending */
    protected function getSortedConversationIds(?AIConversations $foundConversations): array
    {
        $conversationIds = [];
        foreach ($foundConversations?->getElements() ?? [] as $foundConversation) {
            $conversationIds[] = (int)$foundConversation->id;
        }
        sort($conversationIds);
        return $conversationIds;
    }
}
