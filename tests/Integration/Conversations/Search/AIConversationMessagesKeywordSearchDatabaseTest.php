<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Search;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The keyword search of the message repo ({@see DBAIConversationMessages::findByKeyword()},
 * {@see DBAIConversationMessages::findByKeywordAcrossConversations()}) against the database, on both engines: a
 * message matches when it holds ANY word of the query, the message holding more of the words comes first, and the
 * search never leaves its scope (one conversation with an optional index range, the conversations of one account).
 *
 * The conversations hold messages about other subjects too: MariaDB's full-text score of a word every row holds
 * is zero, and a zero score is no match.
 */
class AIConversationMessagesKeywordSearchDatabaseTest extends DatabaseTestCase
{
    protected const int SEARCHING_ACCOUNT_ID = 10;
    protected const int OTHER_ACCOUNT_ID = 20;

    protected const int FIRST_CONVERSATION_ID = 1;
    protected const int SECOND_CONVERSATION_ID = 2;
    protected const int CONVERSATION_OF_OTHER_ACCOUNT_ID = 3;

    protected const int BAKERY_MESSAGE_ID = 101;
    protected const int BAKERY_INVOICE_MESSAGE_ID = 102;
    protected const int HOLIDAY_MESSAGE_ID = 103;
    protected const int LATE_INVOICE_MESSAGE_ID = 104;
    protected const int SECOND_CONVERSATION_BAKERY_MESSAGE_ID = 201;
    protected const int OTHER_ACCOUNT_BAKERY_MESSAGE_ID = 301;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::SEARCHING_ACCOUNT_ID, 'email' => 'owner@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'other-owner@example.test']);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::FIRST_CONVERSATION_ID, 'accountId' => self::SEARCHING_ACCOUNT_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::SECOND_CONVERSATION_ID, 'accountId' => self::SEARCHING_ACCOUNT_ID]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_OF_OTHER_ACCOUNT_ID, 'accountId' => self::OTHER_ACCOUNT_ID]);

        $this->insertMessage(self::BAKERY_MESSAGE_ID, self::FIRST_CONVERSATION_ID, 0, 'Our bakery opens earlier during December');
        $this->insertMessage(self::BAKERY_INVOICE_MESSAGE_ID, self::FIRST_CONVERSATION_ID, 1, 'Please send the bakery invoice to the accountant');
        $this->insertMessage(self::HOLIDAY_MESSAGE_ID, self::FIRST_CONVERSATION_ID, 2, 'The shop stays closed during the summer holiday');
        $this->insertMessage(self::LATE_INVOICE_MESSAGE_ID, self::FIRST_CONVERSATION_ID, 3, 'A reminder for the unpaid invoice went out yesterday');
        $this->insertMessage(105, self::FIRST_CONVERSATION_ID, 4, 'Customers praise the friendly service in their reviews');
        $this->insertMessage(106, self::FIRST_CONVERSATION_ID, 5, 'The website needs a new picture of the storefront');
        $this->insertMessage(self::SECOND_CONVERSATION_BAKERY_MESSAGE_ID, self::SECOND_CONVERSATION_ID, 0, 'A second bakery branch is planned for spring');
        $this->insertMessage(202, self::SECOND_CONVERSATION_ID, 1, 'The newsletter reaches four hundred subscribers');
        $this->insertMessage(self::OTHER_ACCOUNT_BAKERY_MESSAGE_ID, self::CONVERSATION_OF_OTHER_ACCOUNT_ID, 0, 'Another bakery asks about its invoice');
        $this->insertMessage(302, self::CONVERSATION_OF_OTHER_ACCOUNT_ID, 1, 'Parking spaces behind the building are free');
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testAMessageMatchesWhenItHoldsAnyWordOfTheQuery(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findByKeyword('BAKERY invoice', self::FIRST_CONVERSATION_ID, 10);

        self::assertSame(
            [self::BAKERY_MESSAGE_ID, self::BAKERY_INVOICE_MESSAGE_ID, self::LATE_INVOICE_MESSAGE_ID],
            $this->getSortedMessageIds($foundMessages),
            'every message of the conversation holding one of the two words, in any letter case, and no other'
        );
    }

    public function testTheMessageHoldingMoreOfTheWordsComesFirst(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findByKeyword('bakery invoice', self::FIRST_CONVERSATION_ID, 10);

        self::assertSame(self::BAKERY_INVOICE_MESSAGE_ID, $this->getMessageIdsInOrder($foundMessages)[0] ?? null);
    }

    public function testTheLimitKeepsTheBestMatches(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findByKeyword('bakery invoice', self::FIRST_CONVERSATION_ID, 1);

        self::assertSame([self::BAKERY_INVOICE_MESSAGE_ID], $this->getMessageIdsInOrder($foundMessages));
    }

    public function testTheIndexRangeLimitsTheSearchToAPartOfTheConversation(): void
    {
        $foundMessages = (new DBAIConversationMessages())->findByKeyword('bakery invoice', self::FIRST_CONVERSATION_ID, 10, 2, 5);

        self::assertSame([self::LATE_INVOICE_MESSAGE_ID], $this->getSortedMessageIds($foundMessages));
    }

    public function testTheSearchAcrossConversationsReadsTheConversationsOfTheAccountOnly(): void
    {
        $searchingAccount = new Account();
        $searchingAccount->id = self::SEARCHING_ACCOUNT_ID;

        $foundMessages = (new DBAIConversationMessages())->findByKeywordAcrossConversations($searchingAccount, 'bakery', 10);

        self::assertSame(
            [self::BAKERY_MESSAGE_ID, self::BAKERY_INVOICE_MESSAGE_ID, self::SECOND_CONVERSATION_BAKERY_MESSAGE_ID],
            $this->getSortedMessageIds($foundMessages),
            'both conversations of the account, never the conversation of the other account'
        );
    }

    public function testAQueryWithoutASearchableWordFindsNothing(): void
    {
        $messagesRepo = new DBAIConversationMessages();

        self::assertSame([], $this->getMessageIdsInOrder($messagesRepo->findByKeyword('   ', self::FIRST_CONVERSATION_ID, 10)));
        self::assertSame(
            [],
            $this->getMessageIdsInOrder($messagesRepo->findByKeyword('is a', self::FIRST_CONVERSATION_ID, 10)),
            'neither engine searches for words of one or two letters'
        );
        self::assertSame([], $this->getMessageIdsInOrder($messagesRepo->findByKeyword('lighthouse', self::FIRST_CONVERSATION_ID, 10)));
    }

    protected function insertMessage(int $messageId, int $conversationId, int $indexInConversation, string $messageText): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'role' => AIConversationMessage::ROLE_USER,
            'authorType' => AIConversationMessage::AUTHOR_USER,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'message' => $messageText,
        ]);
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

    /** @return int[] The ids of the found messages, smallest first */
    protected function getSortedMessageIds(?AIConversationMessages $foundMessages): array
    {
        $messageIds = $this->getMessageIdsInOrder($foundMessages);
        sort($messageIds);
        return $messageIds;
    }
}
