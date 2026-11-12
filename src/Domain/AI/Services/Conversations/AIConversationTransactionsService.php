<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\TransactionIsolationLevel;
use Throwable;

/**
 * The transactions of the conversation services that must read the latest commits at every statement.
 *
 * The end of a sub-agent run, a cancel and the spawn of a sub-agent first lock a row (`SELECT … FOR UPDATE`) and then
 * read what the transaction that held the lock before has written. At REPEATABLE READ — the default of MariaDB — a
 * transaction reads the snapshot of its first read and would miss exactly those rows; at READ COMMITTED every statement
 * reads what is committed when it starts.
 *
 * The level is set for ONE transaction and never for the session: a session-wide level would stay on the connection of
 * a worker for every later transaction of the application, and behind a connection pooler it would reach another
 * client's session ({@see \DDD\Domain\Base\Repo\DB\Dialect\SqlDialect::beginTransactionAtIsolationLevel()}).
 *
 * Obtained via `DDDService::instance()->getService(AIConversationTransactionsService::class)`.
 */
class AIConversationTransactionsService extends Service
{
    /**
     * Begins a transaction on `$connection` whose every statement reads the latest commits. The caller commits or rolls
     * it back as any other transaction; the level ends with it.
     *
     * Refused inside a transaction that is already open: the level of a running transaction cannot change (MariaDB
     * refuses the statement; PostgreSQL refuses it after the first query and, before the first query, would change the
     * level of the OUTER transaction). A caller that may run inside another transaction has no use for this method.
     *
     * @throws Exception
     * @throws InternalErrorException When a transaction is already open on `$connection`
     * @throws Throwable
     */
    public function beginReadCommittedTransaction(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            throw new InternalErrorException('A READ COMMITTED transaction cannot begin inside a transaction that is already open.');
        }
        EntityManagerFactory::getDialect()->beginTransactionAtIsolationLevel($connection, TransactionIsolationLevel::READ_COMMITTED);
    }

    /**
     * Begins a transaction on `$connection` for a caller that may run inside another transaction. Outside one it is a
     * READ COMMITTED transaction ({@see beginReadCommittedTransaction()}). Inside one it is a nested level of the open
     * transaction: that transaction keeps the level it began with, the caller's commit ends its own level only, and
     * the rows the caller locked stay locked until the outer transaction ends.
     *
     * @throws Exception
     * @throws InternalErrorException
     * @throws Throwable
     */
    public function beginReadCommittedTransactionOrNestInOpenTransaction(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            $connection->beginTransaction();
            return;
        }
        $this->beginReadCommittedTransaction($connection);
    }
}
