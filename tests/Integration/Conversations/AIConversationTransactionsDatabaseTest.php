<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\AIConversationTransactionsService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * The transaction of {@see AIConversationTransactionsService} against the database, on both engines: a statement
 * inside it reads what another connection committed after the transaction's first read, and the level ends with the
 * transaction — the next transaction of the same session reads its first snapshot again.
 *
 * The session of the test runs at REPEATABLE READ on both engines (the default of MariaDB; set for the session on
 * PostgreSQL, whose default is READ COMMITTED), so that the level of one transaction is told apart from the level of
 * the session.
 */
class AIConversationTransactionsDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 100;

    /** A second session on the same database: the other worker that commits between two reads of the test */
    protected ?Connection $connectionOfAnotherWorker = null;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isPostgres()) {
            $this->getDatabaseConnection()->executeStatement("SET default_transaction_isolation = 'repeatable read'");
        }
        $this->insertRow(DBAIConversationModel::getTableName(), [
            'id' => self::CONVERSATION_ID,
            'runState' => AIConversationRunState::IDLE,
        ]);
    }

    protected function onTearDown(): void
    {
        $connection = $this->getDatabaseConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        if ($this->isPostgres()) {
            $connection->executeStatement('RESET default_transaction_isolation');
        }
        $this->connectionOfAnotherWorker?->close();
        $this->connectionOfAnotherWorker = null;
    }

    public function testEveryStatementOfTheTransactionReadsWhatAnotherConnectionCommittedBeforeIt(): void
    {
        $connection = $this->getDatabaseConnection();

        $this->getTransactionsService()->beginReadCommittedTransaction($connection);

        self::assertTrue($connection->isTransactionActive());
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredRunState());
        $this->writeRunStateFromAnotherWorker(AIConversationRunState::RUNNING);
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredRunState(), 'the second read sees the commit');
        $connection->commit();
        self::assertFalse($connection->isTransactionActive());
    }

    public function testTheLevelEndsWithTheTransaction(): void
    {
        $connection = $this->getDatabaseConnection();
        $this->getTransactionsService()->beginReadCommittedTransaction($connection);
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredRunState());
        $connection->commit();

        $connection->beginTransaction();
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredRunState());
        $this->writeRunStateFromAnotherWorker(AIConversationRunState::RUNNING);
        self::assertSame(
            AIConversationRunState::IDLE,
            $this->findStoredRunState(),
            'the next transaction of the session reads the snapshot of its first read again'
        );
        $connection->commit();
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredRunState());
    }

    public function testATransactionThatIsAlreadyOpenRefusesTheLevelAndStaysAsItIs(): void
    {
        $connection = $this->getDatabaseConnection();
        $connection->beginTransaction();
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredRunState());

        try {
            $this->getTransactionsService()->beginReadCommittedTransaction($connection);
            self::fail('an open transaction must refuse the level');
        } catch (InternalErrorException) {
        }

        self::assertTrue($connection->isTransactionActive());
        self::assertFalse($connection->isRollbackOnly(), 'the open transaction of the caller is untouched');
        $this->writeRunStateFromAnotherWorker(AIConversationRunState::RUNNING);
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredRunState(), 'and still reads its own snapshot');
        $connection->commit();
    }

    public function testACallerThatMayRunInsideAnotherTransactionGetsAReadCommittedTransactionOutsideOne(): void
    {
        $connection = $this->getDatabaseConnection();

        $this->getTransactionsService()->beginReadCommittedTransactionOrNestInOpenTransaction($connection);

        self::assertSame(1, $connection->getTransactionNestingLevel());
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredRunState());
        $this->writeRunStateFromAnotherWorker(AIConversationRunState::RUNNING);
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredRunState(), 'the second read sees the commit');
        $connection->commit();
        self::assertFalse($connection->isTransactionActive());
    }

    public function testInsideAnOpenTransactionTheCallerGetsALevelOfItAndThatTransactionKeepsItsLevel(): void
    {
        $connection = $this->getDatabaseConnection();
        $connection->beginTransaction();
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredRunState());

        $this->getTransactionsService()->beginReadCommittedTransactionOrNestInOpenTransaction($connection);

        self::assertSame(2, $connection->getTransactionNestingLevel());
        $this->writeRunStateFromAnotherWorker(AIConversationRunState::RUNNING);
        self::assertSame(AIConversationRunState::IDLE, $this->findStoredRunState(), 'the open transaction still reads its own snapshot');
        $connection->commit();
        self::assertTrue($connection->isTransactionActive(), 'the commit of the caller ends its own level only');
        $connection->commit();
        self::assertSame(AIConversationRunState::RUNNING, $this->findStoredRunState());
    }

    protected function getTransactionsService(): AIConversationTransactionsService
    {
        /** @var AIConversationTransactionsService $aIConversationTransactionsService */
        $aIConversationTransactionsService = DDDService::instance()->getService(AIConversationTransactionsService::class);
        return $aIConversationTransactionsService;
    }

    /** The run state of the test's conversation as the connection of the test reads it now. */
    protected function findStoredRunState(): string
    {
        $storedConversation = $this->findStoredRow(DBAIConversationModel::getTableName(), self::CONVERSATION_ID);
        self::assertNotNull($storedConversation);
        return (string)$storedConversation['runState'];
    }

    /** Writes and commits the run state on a second session, as another worker would. */
    protected function writeRunStateFromAnotherWorker(string $runState): void
    {
        if ($this->connectionOfAnotherWorker === null) {
            $connection = $this->getDatabaseConnection();
            $this->connectionOfAnotherWorker = DriverManager::getConnection($connection->getParams(), $connection->getConfiguration());
        }
        $this->connectionOfAnotherWorker->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runState` = ? WHERE `id` = ?',
            [$runState, self::CONVERSATION_ID]
        );
    }
}
