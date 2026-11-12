<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageIndexLock;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use RuntimeException;

/**
 * The message-index lock and the claim-fenced write of {@see AIConversationMessagesService} against the database, on
 * both engines, with a second session as the other worker: while a writer holds the lock no other session gets it, the
 * rows of an open write are invisible until its commit, and every way a write ends — commit, rollback, a lost claim, a
 * lock that was not free — leaves the lock free and no transaction open. Also the truncation that runs under the lock
 * and the reads by index.
 *
 * MariaDB holds the lock on the session; PostgreSQL holds it for the transaction the hold begins. The tests ask the
 * same questions of both.
 */
class AIConversationMessagesIndexLockAndClaimFencedWriteDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 100;

    protected const string RUN_TOKEN = 'token-of-this-worker';

    /** A second session on the same database: the other worker */
    protected ?Connection $connectionOfAnotherWorker = null;

    protected function setUp(): void
    {
        parent::setUp();
        // the writers of this test are a worker's: no account is signed in
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID, 'runToken' => self::RUN_TOKEN]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        AIConversationMessagesService::forgetClaimFencedWriteStateForNewJob();
        $connection = $this->getDatabaseConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        if ($this->connectionOfAnotherWorker !== null) {
            while ($this->connectionOfAnotherWorker->isTransactionActive()) {
                $this->connectionOfAnotherWorker->rollBack();
            }
            $this->connectionOfAnotherWorker->close();
            $this->connectionOfAnotherWorker = null;
        }
    }

    public function testAnInboundRowIsStoredAboveTheNewestStoredIndexAndTheLockIsFreeAfterIt(): void
    {
        $this->insertTextRow(601, 0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER);
        $this->insertTextRow(602, 1, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT);
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation(self::CONVERSATION_ID);
        $ownerMessage = $this->buildOwnerMessage('a second question');
        $ownerMessage->indexInConversation = 0;

        $messagesService->persistInboundRowIndexSafe($conversation, $ownerMessage);

        self::assertSame(2, $ownerMessage->indexInConversation, 'a view that does not hold the stored rows writes above them');
        self::assertNotNull($ownerMessage->id);
        self::assertSame(3, $this->countRowsAnotherWorkerSees(), 'the row is committed');
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
        self::assertTrue($this->canAnotherWorkerTakeTheLock());
        self::assertSame(2, $messagesService->findNewestMessageIndex($conversation));
        // PostgreSQL numbers a new row from its sequence, which the rows stored with a given id did not advance
        self::assertSame(max(602, (int)$ownerMessage->id), $messagesService->findHighestMessageId($conversation));
        self::assertTrue($messagesService->isMessageIndexTaken($conversation, 2));
        self::assertFalse($messagesService->isMessageIndexTaken($conversation, 3));
    }

    public function testTheRowsOfAnOpenClaimFencedWriteAreInvisibleAndItsLockIsHeldUntilTheCommit(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation(self::CONVERSATION_ID);

        $writeScope = $messagesService->openClaimFencedWrite($conversation, self::RUN_TOKEN);
        $messagesService->persistNewRowUnderRunClaim($conversation, $this->buildOwnerMessage('written inside the open write'));

        self::assertTrue(AIConversationMessagesService::isClaimFencedWriteOpen());
        self::assertSame(0, $this->countRowsAnotherWorkerSees(), 'the write has not committed');
        self::assertFalse($this->canAnotherWorkerTakeTheLock(), 'the writer holds the lock');

        $messagesService->commitClaimFencedWrite($writeScope);

        self::assertFalse(AIConversationMessagesService::isClaimFencedWriteOpen());
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
        self::assertSame(1, $this->countRowsAnotherWorkerSees());
        self::assertTrue($this->canAnotherWorkerTakeTheLock());
    }

    public function testAWriteThatRollsBackStoresNothingAndGivesTheLockBack(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation(self::CONVERSATION_ID);
        $writeScope = $messagesService->openClaimFencedWrite($conversation, self::RUN_TOKEN);
        $messagesService->persistNewRowUnderRunClaim($conversation, $this->buildOwnerMessage('never stored'));
        $writeError = new RuntimeException('the write failed');

        try {
            $messagesService->rollBackClaimFencedWrite($writeScope, $writeError);
        } catch (RuntimeException $rethrownError) {
            self::assertSame($writeError, $rethrownError);
        }

        self::assertFalse(AIConversationMessagesService::isClaimFencedWriteOpen());
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
        self::assertSame(0, $this->countRowsAnotherWorkerSees());
        self::assertTrue($this->canAnotherWorkerTakeTheLock());
    }

    public function testAWriterWhoseClaimAnotherWorkerTookWritesNothingAndGivesTheLockBack(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation(self::CONVERSATION_ID);

        try {
            $messagesService->openClaimFencedWrite($conversation, 'token-of-an-earlier-claim');
            self::fail('a lost claim must refuse the write');
        } catch (AgentRunClaimLostException) {
        }

        self::assertFalse(AIConversationMessagesService::isClaimFencedWriteOpen());
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
        self::assertTrue($this->canAnotherWorkerTakeTheLock());
        self::assertFalse(AIConversationMessageIndexLock::isHeldInThisProcess($this->getLockName()));
    }

    public function testAWriterThatHoldsTheLockAsksAgainWithoutTakingItTwice(): void
    {
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation(self::CONVERSATION_ID);

        $outerHold = $messagesService->lockMessageIndex($conversation);
        $innerHold = $messagesService->lockMessageIndex($conversation);

        self::assertTrue($outerHold->isReleaseOwed());
        self::assertFalse($innerHold->isReleaseOwed(), 'the process holds the lock already');
        $messagesService->unlockMessageIndex($innerHold);
        self::assertFalse($this->canAnotherWorkerTakeTheLock(), 'the hold that took the lock still has it');
        $messagesService->unlockMessageIndex($outerHold);
        self::assertTrue($this->canAnotherWorkerTakeTheLock());
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
    }

    public function testALockAnotherWorkerHoldsRefusesTheWriterAfterTwoWaits(): void
    {
        $connectionOfAnotherWorker = $this->getConnectionOfAnotherWorker();
        $connectionOfAnotherWorker->beginTransaction();
        self::assertTrue($this->getSqlDialect()->acquireTransactionLock($connectionOfAnotherWorker, $this->getLockName(), 0));

        try {
            $this->getMessagesService()->lockMessageIndex($this->buildConversation(self::CONVERSATION_ID));
            self::fail('a lock that is not free must refuse the writer');
        } catch (MessageIndexLockUnavailableException) {
        }

        self::assertFalse(AIConversationMessageIndexLock::isHeldInThisProcess($this->getLockName()));
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive(), 'no transaction of a failed attempt stays open');
        $connectionOfAnotherWorker->rollBack();
        $this->getSqlDialect()->releaseTransactionLock($connectionOfAnotherWorker, $this->getLockName());
    }

    public function testATruncationMarksTheRowsFromTheIndexOnAndTheChildrenTheyStarted(): void
    {
        $this->insertTextRow(601, 0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER);
        $this->insertTextRow(602, 1, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT);
        $this->insertTextRow(603, 2, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 201, 'delegatedFromMessageId' => 603]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 202, 'delegatedFromMessageId' => 601]);
        $this->insertTextRow(701, 0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_AGENT, 201);
        $this->insertTextRow(702, 1, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, 201);
        $this->insertTextRow(801, 0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_AGENT, 202);
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation(self::CONVERSATION_ID);

        self::assertSame(4, $messagesService->truncateFrom($conversation, 1), 'two rows of the conversation and the two rows of the child they started');
        self::assertSame(0, $messagesService->truncateFrom($conversation, 1), 'a truncated row is not truncated again');

        $messageTableName = DBAIConversationMessageModel::getTableName();
        self::assertNull($this->findStoredRow($messageTableName, 601)['truncatedAt']);
        self::assertNotNull($this->findStoredRow($messageTableName, 602)['truncatedAt']);
        self::assertNotNull($this->findStoredRow($messageTableName, 603)['truncatedAt']);
        self::assertNotNull($this->findStoredRow($messageTableName, 701)['truncatedAt']);
        self::assertNotNull($this->findStoredRow($messageTableName, 702)['truncatedAt']);
        self::assertNull($this->findStoredRow($messageTableName, 801)['truncatedAt'], 'the child of a row that stays');
        self::assertFalse($this->getDatabaseConnection()->isTransactionActive());
        self::assertTrue($this->canAnotherWorkerTakeTheLock());
    }

    public function testTheReadsByIndexAnswerFromTheRowsThatAreNotTruncated(): void
    {
        $this->insertTextRow(601, 0, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER);
        $this->insertTextRow(602, 1, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT);
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => 603,
            'aiConversationId' => self::CONVERSATION_ID,
            'indexInConversation' => 2,
            'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'toolCall' => '{"toolCallId":"call_1","name":"load_skills","arguments":{"skill_names":["Seo.Keywords","Presence.Posts"]},"status":"COMPLETED","result":null}',
        ]);
        $this->insertTextRow(604, 3, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER);
        $this->insertTextRow(605, 4, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, self::CONVERSATION_ID, '2026-01-02 03:04:05');
        $messagesService = $this->getMessagesService();
        $conversation = $this->buildConversation(self::CONVERSATION_ID);

        self::assertSame(1, $messagesService->findNewestAssistantTextIndex($conversation), 'the truncated answer does not count');
        self::assertSame(604, $messagesService->findNewestOwnerTextMessageId($conversation));
        self::assertNull($messagesService->findNewestAssistantTextIndex($this->buildConversation(999)));

        $integrityRows = $messagesService->findIntegrityProjectionRows($conversation);
        self::assertSame([0, 1, 2, 3, 4], array_map('intval', array_column($integrityRows, 'indexInConversation')), 'every row, the truncated one included');
        self::assertSame([false, false, false, false, true], array_map('boolval', array_column($integrityRows, 'truncated')));
        self::assertNull($integrityRows[0]['toolName']);
        self::assertSame('load_skills', $integrityRows[2]['toolName']);
        self::assertSame('COMPLETED', $integrityRows[2]['toolStatus']);
        self::assertSame('call_1', $integrityRows[2]['toolCallId']);
        self::assertSame(['Seo.Keywords', 'Presence.Posts'], json_decode((string)$integrityRows[2]['requestedSkillNames'], true, 512, JSON_THROW_ON_ERROR));

        $turnMessages = $messagesService->findTurnMessages($conversation, 604)->getElements();
        self::assertSame([604], array_map(static fn (AIConversationMessage $turnMessage): int => (int)$turnMessage->id, $turnMessages), 'the turn the owner row opened, without the truncated row');
        $taskSliceMessages = $messagesService->findTaskSliceMessages($conversation, 601)->getElements();
        self::assertSame(
            [601, 602, 603, 604],
            array_map(static fn (AIConversationMessage $taskSliceMessage): int => (int)$taskSliceMessage->id, $taskSliceMessages),
            'from the owner row up to and including the next owner row, which supersedes the task'
        );
    }

    public function testTheScheduleSpendOfTheMonthSumsTheRowsOfScheduledRunsThatCountAgainstTheBudget(): void
    {
        $this->insertRow(DBAccountModel::getTableName(), ['id' => 11, 'email' => 'first@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => 12, 'email' => 'second@example.test']);
        $this->insertRow(DBAIConversationScheduleModel::getTableName(), ['id' => 31, 'accountId' => 11, 'task' => 'a daily task']);
        $this->insertRow(DBAIConversationScheduleModel::getTableName(), ['id' => 32, 'accountId' => 12, 'task' => 'a task of another account']);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 101, 'accountId' => 11, 'scheduleId' => 31]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 102, 'accountId' => 11, 'scheduleId' => 31, 'budgetBypass' => 1]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 103, 'accountId' => 11]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => 104, 'accountId' => 12, 'scheduleId' => 32]);
        $this->insertCostRow(901, 101, 1, 0.25, '2026-03-05 10:00:00', '{"name":"searchTheWeb","executionCosts":{"totalCostInUSD":0.05}}');
        $this->insertCostRow(902, 101, 0, 0.5, '2026-02-27 10:00:00', null);
        $this->insertCostRow(903, 102, 0, 1.0, '2026-03-05 10:00:00', null);
        $this->insertCostRow(904, 103, 0, 2.0, '2026-03-05 10:00:00', null);
        $this->insertCostRow(905, 104, 0, 4.0, '2026-03-05 10:00:00', null);
        $messagesService = $this->getMessagesService();

        self::assertEqualsWithDelta(0.3, $messagesService->findScheduleSpendMonthToDateUsdForAccounts([11], '2026-03-01 00:00:00'), 1e-9);
        self::assertEqualsWithDelta(4.3, $messagesService->findScheduleSpendMonthToDateUsdForAccounts([11, 12], '2026-03-01 00:00:00'), 1e-9);
        self::assertSame(0.0, $messagesService->findScheduleSpendMonthToDateUsdForAccounts([], '2026-03-01 00:00:00'));
    }

    protected function getMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** A conversation object that names the stored row `$conversationId` and holds none of its messages. */
    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        return $conversation;
    }

    /** A new owner text of the test's conversation, not stored yet. */
    protected function buildOwnerMessage(string $text): AIConversationMessage
    {
        $ownerMessage = new AIConversationMessage();
        $ownerMessage->aiConversationId = self::CONVERSATION_ID;
        $ownerMessage->role = AIConversationMessage::ROLE_USER;
        $ownerMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $ownerMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerMessage->message = $text;
        return $ownerMessage;
    }

    protected function getLockName(): string
    {
        return AIConversationMessageIndexLock::buildLockNameForConversation($this->buildConversation(self::CONVERSATION_ID));
    }

    protected function insertTextRow(
        int $messageId,
        int $indexInConversation,
        string $role,
        string $authorType,
        int $conversationId = self::CONVERSATION_ID,
        ?string $truncatedAt = null
    ): void {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'messageType' => AIConversationMessage::MESSAGE_TEXT,
            'role' => $role,
            'authorType' => $authorType,
            'message' => 'text of row ' . $messageId,
            'truncatedAt' => $truncatedAt,
        ]);
    }

    /** A row of a conversation with a model cost, a creation moment and, when given, a tool call document. */
    protected function insertCostRow(int $messageId, int $conversationId, int $indexInConversation, float $costInUSD, string $created, ?string $toolCallJson): void
    {
        $this->insertRow(DBAIConversationMessageModel::getTableName(), [
            'id' => $messageId,
            'aiConversationId' => $conversationId,
            'indexInConversation' => $indexInConversation,
            'messageType' => $toolCallJson === null ? AIConversationMessage::MESSAGE_TEXT : AIConversationMessage::MESSAGE_TOOL_CALL,
            'role' => AIConversationMessage::ROLE_ASSISTANT,
            'message' => '',
            'toolCall' => $toolCallJson,
            'costInUSD' => $costInUSD,
            'created' => $created,
        ]);
    }

    protected function getConnectionOfAnotherWorker(): Connection
    {
        if ($this->connectionOfAnotherWorker === null) {
            $connection = $this->getDatabaseConnection();
            $this->connectionOfAnotherWorker = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration());
        }
        return $this->connectionOfAnotherWorker;
    }

    /** How many rows of the test's conversation a session other than the writer's reads right now. */
    protected function countRowsAnotherWorkerSees(): int
    {
        return (int)$this->getConnectionOfAnotherWorker()->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `aiConversationId` = ?',
            [self::CONVERSATION_ID]
        );
    }

    /** Whether the message-index lock of the test's conversation is free for another session; a lock it got is given back. */
    protected function canAnotherWorkerTakeTheLock(): bool
    {
        $connectionOfAnotherWorker = $this->getConnectionOfAnotherWorker();
        $connectionOfAnotherWorker->beginTransaction();
        try {
            return $this->getSqlDialect()->acquireTransactionLock($connectionOfAnotherWorker, $this->getLockName(), 0);
        } finally {
            $connectionOfAnotherWorker->rollBack();
            $this->getSqlDialect()->releaseTransactionLock($connectionOfAnotherWorker, $this->getLockName());
        }
    }
}
