<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Search;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\SemanticSearchService;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The eval-run filter of the search ({@see SemanticSearchService}) against the database, on both engines: a found
 * message of an eval run is dropped, unless the eval run holds seeded history; every other message stays.
 */
class SemanticSearchServiceEvalRunFilterDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 10;

    protected const int CONVERSATION_WITHOUT_RUN_SETTINGS_ID = 1;
    protected const int EVAL_RUN_CONVERSATION_ID = 2;
    protected const int EVAL_RUN_WITH_SEEDED_HISTORY_CONVERSATION_ID = 3;
    protected const int CONVERSATION_MARKED_NOT_AN_EVAL_RUN_ID = 4;
    protected const int EVAL_RUN_WITH_SEEDED_HISTORY_NULL_CONVERSATION_ID = 5;
    protected const int EVAL_RUN_WITH_SEEDED_HISTORY_FALSE_CONVERSATION_ID = 6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'owner@example.test']);
        $this->insertConversation(self::CONVERSATION_WITHOUT_RUN_SETTINGS_ID, null);
        $this->insertConversation(self::EVAL_RUN_CONVERSATION_ID, ['evalRun' => true]);
        $this->insertConversation(self::EVAL_RUN_WITH_SEEDED_HISTORY_CONVERSATION_ID, ['evalRun' => true, 'evalSeededHistory' => true]);
        $this->insertConversation(self::CONVERSATION_MARKED_NOT_AN_EVAL_RUN_ID, ['evalRun' => false, 'evalSeededHistory' => false]);
        $this->insertConversation(self::EVAL_RUN_WITH_SEEDED_HISTORY_NULL_CONVERSATION_ID, ['evalRun' => true, 'evalSeededHistory' => null]);
        $this->insertConversation(self::EVAL_RUN_WITH_SEEDED_HISTORY_FALSE_CONVERSATION_ID, ['evalRun' => true, 'evalSeededHistory' => false]);
    }

    public function testTheMessagesOfAnEvalRunWithoutSeededHistoryAreDropped(): void
    {
        $foundMessages = [];
        for ($conversationId = 1; $conversationId <= 6; $conversationId++) {
            $foundMessages[] = $this->buildFoundMessage(100 + $conversationId, $conversationId);
        }
        $foundMessages[] = $this->buildFoundMessage(200, self::EVAL_RUN_CONVERSATION_ID);

        $keptMessages = (new SemanticSearchServiceWithCallableEvalRunFilter())->dropMessagesOfEvalRuns($foundMessages);

        $keptMessageIds = [];
        foreach ($keptMessages as $keptMessage) {
            $keptMessageIds[] = (int)$keptMessage->id;
        }
        self::assertSame(
            [
                100 + self::CONVERSATION_WITHOUT_RUN_SETTINGS_ID,
                100 + self::EVAL_RUN_WITH_SEEDED_HISTORY_CONVERSATION_ID,
                100 + self::CONVERSATION_MARKED_NOT_AN_EVAL_RUN_ID,
            ],
            $keptMessageIds
        );
    }

    public function testMessagesOfConversationsThatAreNoEvalRunsStayAsTheyAre(): void
    {
        $foundMessages = [
            $this->buildFoundMessage(101, self::CONVERSATION_WITHOUT_RUN_SETTINGS_ID),
            $this->buildFoundMessage(104, self::CONVERSATION_MARKED_NOT_AN_EVAL_RUN_ID),
        ];

        self::assertSame($foundMessages, (new SemanticSearchServiceWithCallableEvalRunFilter())->dropMessagesOfEvalRuns($foundMessages));
    }

    /** @param array<string, bool|null>|null $runSettings */
    protected function insertConversation(int $conversationId, ?array $runSettings): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), [
            'id' => $conversationId,
            'accountId' => self::ACCOUNT_ID,
            'runSettings' => $runSettings === null ? null : json_encode($runSettings, JSON_THROW_ON_ERROR),
        ]);
    }

    protected function buildFoundMessage(int $messageId, int $conversationId): AIConversationMessage
    {
        $foundMessage = new AIConversationMessage();
        $foundMessage->id = $messageId;
        $foundMessage->aiConversationId = $conversationId;
        return $foundMessage;
    }
}
