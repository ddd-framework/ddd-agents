<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Search;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The search of a space's conversation list by their summaries ({@see DBAIConversations::findByAgentSpaceAndSearchTerm()})
 * on both engines: the summary is a JSON column, so its title, text and outcome are read as text (a LOWER over the JSON fails
 * on PostgreSQL); case and umlauts do not matter, and a LIKE wildcard in the term is a literal character.
 */
class AIConversationsSummarySearchDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 12;
    protected const int AGENT_SPACE_ID = 22;

    protected const int BAKERY_CONVERSATION_ID = 41;
    protected const int MUNICH_CONVERSATION_ID = 42;
    protected const int DISCOUNT_CONVERSATION_ID = 43;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'summary-owner@example.test']);
        $this->insertAgentSpaceRow(['id' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
        $this->insertConversation(self::BAKERY_CONVERSATION_ID, ['title' => 'Bakery opening hours', 'text' => 'The owner moves the opening to six.']);
        $this->insertConversation(self::MUNICH_CONVERSATION_ID, ['title' => 'Second shop', 'text' => 'The second shop opens in München next spring.']);
        $this->insertConversation(self::DISCOUNT_CONVERSATION_ID, ['title' => 'Spring campaign', 'text' => 'A 10% discount for regulars.', 'outcome' => 'The flyer goes to the printer on Friday.']);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testATermInTheTitleFindsTheConversationWhateverItsCase(): void
    {
        $this->assertSame([self::BAKERY_CONVERSATION_ID], $this->findConversationIdsBySearchTerm('BAKERY'));
    }

    public function testATermInTheTextFindsTheConversationAlsoWithAnUmlaut(): void
    {
        $this->assertSame([self::MUNICH_CONVERSATION_ID], $this->findConversationIdsBySearchTerm('münchen'));
    }

    public function testATermInTheOutcomeFindsTheConversation(): void
    {
        $this->assertSame([self::DISCOUNT_CONVERSATION_ID], $this->findConversationIdsBySearchTerm('printer'));
    }

    public function testAKeyOfTheSummaryIsNoMatch(): void
    {
        $this->assertSame([], $this->findConversationIdsBySearchTerm('outcome'));
    }

    public function testAPercentSignInTheTermIsALiteralCharacter(): void
    {
        $this->assertSame([self::DISCOUNT_CONVERSATION_ID], $this->findConversationIdsBySearchTerm('10%'));
        $this->assertSame([], $this->findConversationIdsBySearchTerm('9%'));
    }

    /**
     * @return int[]
     */
    protected function findConversationIdsBySearchTerm(string $searchTerm): array
    {
        /** @var DBAIConversations $conversationsRepository */
        $conversationsRepository = AIConversations::getRepoClassInstance();
        $conversations = $conversationsRepository->findByAgentSpaceAndSearchTerm($this->buildAgentSpace(), $searchTerm);
        $conversationIds = [];
        foreach ($conversations?->getElements() ?? [] as $conversation) {
            $conversationIds[] = (int)$conversation->id;
        }
        sort($conversationIds);
        return $conversationIds;
    }

    /**
     * @param array<string, string> $summary
     */
    protected function insertConversation(int $conversationId, array $summary): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), [
            'id' => $conversationId,
            'accountId' => self::ACCOUNT_ID,
            'agentSpaceId' => self::AGENT_SPACE_ID,
            'summary' => json_encode($summary),
        ]);
    }

    protected function buildAgentSpace(): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;
        $agentSpace->accountId = self::ACCOUNT_ID;
        return $agentSpace;
    }
}
