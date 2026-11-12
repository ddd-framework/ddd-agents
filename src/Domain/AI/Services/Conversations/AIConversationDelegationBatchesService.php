<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationChildCounts;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentDelegationResumeClaim;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatches;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationResumeCondition;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRuns;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatch;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatches;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Throwable;

/**
 * The delegation fork-join coordinator service. Owns the exactly-once settle transaction
 * ({@see claimParentResumeOnChildTerminal}), batch creation ({@see createBatch}), and the reaper/inspection queries. The pure
 * join predicates live on {@see AIConversationDelegationBatch}; everything repo/transaction-bound lives here.
 *
 * @method AIConversationDelegationBatch find(string|int|null $entityId, bool $useEntityRegistrCache = true)
 * @method AIConversationDelegationBatches findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AIConversationDelegationBatch update(AIConversationDelegationBatch $entity)
 * @method DBAIConversationDelegationBatch getEntityRepoClassInstance()
 * @method DBAIConversationDelegationBatches getEntitySetRepoClassInstance()
 */
class AIConversationDelegationBatchesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AIConversationDelegationBatch::class;

    /**
     * Fork a delegation batch: persist the coordinator row with its expected child count, resume condition, and absolute
     * deadline. The caller stamps each spawned child's {@see AIConversation::$delegationBatchId} with
     * the returned batch's id and dispatches the children AFTER this returns (so `expectedDelegationCount` is final
     * before any child can settle). `spawnedByMessage` is the assistant turn message that emitted the delegate calls
     * (NULL for a service-created batch).
     *
     * @throws InternalErrorException when the parent conversation is not stored: the batch row references it by id,
     *     and the end of a sub-agent finds its parent through that id
     */
    public function createBatch(
        AIConversation $parentConversation,
        int $expectedDelegationCount,
        string $delegateWakeCondition = AIConversationDelegationResumeCondition::ALL,
        ?int $quorumSuccessThreshold = null,
        int $timeoutSeconds = 300,
        ?AIConversationMessage $spawnedByMessage = null
    ): AIConversationDelegationBatch {
        if (!isset($parentConversation->id)) {
            throw new InternalErrorException('A conversation that is not stored cannot delegate: the delegation batch references its parent conversation by id.');
        }
        $batch = AIConversationDelegationBatch::newInstance();
        $batch->parentConversationId = (int)$parentConversation->id;
        $batch->expectedDelegationCount = $expectedDelegationCount;
        $batch->delegateWakeCondition = $delegateWakeCondition;
        $batch->quorumSuccessThreshold = $quorumSuccessThreshold;
        $batch->timeoutSeconds = $timeoutSeconds;
        $batch->deadlineAt = (new DateTime())->modify('+' . $timeoutSeconds . ' seconds');
        $batch->spawnedByMessageId = $spawnedByMessage?->id;
        // update(0) — new batch, only own scalars/FK-ids set (spawnedByMessage/delegationChildren relation
        // objects are NOT populated here); depth 0 writes the batch row. No child to cascade.
        $batch->update(0);
        return $batch;
    }

    /** How long after its end a run whose notification is missing waits before the recovery re-sends it. */
    public const int SUB_AGENT_RUN_NOTIFICATION_RECOVERY_DELAY_SECONDS = 60;

    /** How long a sub-agent's child may wait QUEUED for a slot before its run ends TIMED_OUT. */
    public const int SUB_AGENT_QUEUED_MAX_WAIT_SECONDS = 1800;

    /** How far back the recovery looks for ended runs whose notification is missing. */
    public const int SUB_AGENT_RUN_NOTIFICATION_RECOVERY_LOOKBACK_SECONDS = 86400;

    /**
     * The deadline row of ONE run of a sub-agent: the batch row shrinks to one member, `spawnedByMessageId`
     * = the `delegate` row of the sub-agent, the deadline `timeoutSeconds` from now. The child's `delegationBatchId`
     * points at it while this run is its current one. The condition column carries no wait rule any more: ALL for a run
     * whose end resumes the parent, BACKGROUND for platform work whose end does not — so every
     * reader of "the parent still waits for a sub-agent" (the release's RUNNING status, an application's question
     * whether children still run, the resume on owner input) leaves the platform's background work out, as it did
     * for the BACKGROUND batch.
     */
    public function createSubAgentRunDeadline(
        AIConversation $parentConversation,
        AIConversationMessage $delegateCallMessage,
        int $timeoutSeconds,
        bool $notifiesParentWithoutResuming = false
    ): AIConversationDelegationBatch {
        return $this->createBatch(
            $parentConversation,
            1,
            $notifiesParentWithoutResuming ? AIConversationDelegationResumeCondition::BACKGROUND : AIConversationDelegationResumeCondition::ALL,
            null,
            $timeoutSeconds,
            $delegateCallMessage
        );
    }

    /**
     * The run of a deadline row ended: `settledAt` is stamped once — a second end (a redelivery,
     * the timeout after the child's own end) matches zero rows. Returns whether THIS call stamped it.
     *
     * `foldedAt` is stamped in the same statement: a run end reaches the parent as its one notification row, never as a
     * fold of the legacy batch. Every legacy reader of "settled but unread" (`settledAt IS NOT NULL AND foldedAt IS
     * NULL` — the derive's trigger B, the reaper's sweep 3, an application's close rule, the background note) therefore
     * never sees a deadline row; the legacy batches keep their own writers and stay readable unchanged.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function markSubAgentRunDeadlineEnded(int $deadlineId, DateTime $endedAt): bool
    {
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationDelegationBatchModel::getTableName() . '` SET `settledAt` = :endedAt, `foldedAt` = :endedAt WHERE `id` = :deadlineId AND `settledAt` IS NULL',
            ['endedAt' => $endedAt->format(DateTime::SIMPLE), 'deadlineId' => $deadlineId]
        ) === 1;
    }

    /**
     * The deadline rows whose run ended but whose one notification never reached the parent (a crash
     * between the run-end commit and the notifier): ended more than {@see SUB_AGENT_RUN_NOTIFICATION_RECOVERY_DELAY_SECONDS}
     * ago and within {@see SUB_AGENT_RUN_NOTIFICATION_RECOVERY_LOOKBACK_SECONDS}, of this environment's parents, whose
     * sub-agent record (the deadline row's `spawnedByMessageId`) holds an ended run without `reportedToParentAt` — ANY
     * run, not only the current one (a crash between run k's end and its notification, then run k+1
     * opened, must still deliver run k's): a run with `finishedAt` and without `reportedToParentAt`. The statement reads
     * the `runs` of every candidate row and the runs are compared in PHP: a JSON path with a wildcard has no form that
     * runs on MariaDB and on PostgreSQL. A record without
     * a deadline row (a refusal, a sync run) never matches; a legacy row (no sub-agent record) neither. With `$parentConversation` the same read for one parent (its turn head), oldest
     * first. Raw, rights-agnostic (the reaper and the worker read it).
     *
     * @return int[]
     * @throws \Doctrine\DBAL\Exception
     */
    public function findEndedUnreportedSubAgentRunDeadlineIds(?AIConversation $parentConversation = null): array
    {
        $now = new DateTime();
        $endedBefore = (clone $now)->modify('-' . self::SUB_AGENT_RUN_NOTIFICATION_RECOVERY_DELAY_SECONDS . ' seconds');
        $endedSince = (clone $now)->modify('-' . self::SUB_AGENT_RUN_NOTIFICATION_RECOVERY_LOOKBACK_SECONDS . ' seconds');
        $environment = AIConversationsService::currentEnvironment();
        $parameters = [
            'endedBefore' => $endedBefore->format(DateTime::SIMPLE),
            'endedSince' => $endedSince->format(DateTime::SIMPLE),
            'environment' => $environment,
        ];
        $parentCondition = '';
        if ($parentConversation !== null) {
            if (!isset($parentConversation->id)) {
                return [];
            }
            $parentCondition = ' AND `deadline`.`parentConversationId` = :parentConversationId';
            $parameters['parentConversationId'] = (int)$parentConversation->id;
        }
        // each deadline row joins exactly one `delegate` row and one parent, so no row comes back twice
        $deadlineRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `deadline`.`id` AS `deadlineId`, '
            . EntityManagerFactory::getDialect()->jsonText('`delegateCall`.`delegationResults`', JsonPath::fromKeys(DelegationResults::SUB_AGENT_KEY, 'runs'))
            . ' AS `storedSubAgentRuns`'
            . ' FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '` `deadline`'
            . ' JOIN `' . DBAIConversationMessageModel::getTableName() . '` `delegateCall` ON `delegateCall`.`id` = `deadline`.`spawnedByMessageId`'
            . ' JOIN `' . DBAIConversationModel::getTableName() . '` `parentConversation` ON `parentConversation`.`id` = `deadline`.`parentConversationId`'
            . ' WHERE `deadline`.`settledAt` IS NOT NULL AND `deadline`.`settledAt` < :endedBefore AND `deadline`.`settledAt` >= :endedSince'
            . $parentCondition
            . ' AND ' . self::buildDelegateCallCarriesSubAgentRecordSql()
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate('parentConversation', $environment)
            . ' ORDER BY `deadline`.`id` ASC',
            $parameters
        );
        $endedUnreportedDeadlineIds = [];
        foreach ($deadlineRows as $deadlineRow) {
            $storedSubAgentRuns = is_string($deadlineRow['storedSubAgentRuns'] ?? null)
                ? json_decode($deadlineRow['storedSubAgentRuns'], true)
                : null;
            if (is_array($storedSubAgentRuns) && DelegationSubAgentRuns::fromStoredArray($storedSubAgentRuns)->hasFinishedRunNotReportedToParent()) {
                $endedUnreportedDeadlineIds[] = (int)$deadlineRow['deadlineId'];
            }
        }
        return $endedUnreportedDeadlineIds;
    }

    /**
     * The exactly-once settle transaction: a delegation CHILD just reached a terminal verdict — record its
     * terminal run-state and, if this completion first satisfies the batch's resume condition, claim the single parent
     * wake. Returns TRUE iff THIS call won the parent resume (the caller then promotes queued siblings, cancels losers on a
     * QUORUM/ANY finish, and dispatches the parent resume — all AFTER this commits).
     *
     * Correctness (READ COMMITTED, lock-first, one transaction):
     *  - The `SELECT … FOR UPDATE` serializes all settlers of this batch on its row BEFORE any count is read, so the
     *    derived `COUNT(*)` over the children observes every sibling whose terminal commit preceded the lock
     *    (REPEATABLE READ's snapshot would miss a concurrently-finishing sibling → two finishers each miss the other →
     *    the batch never settles → permanent hang).
     *  - The child terminal UPDATE is token-guarded (`runToken` + `runState='RUNNING'`) → it is the SOLE writer of the
     *    child's terminal state and contributes EXACTLY ONCE; a redelivered/duplicate handler gets `won != 1` and rolls
     *    back. It runs BEFORE the settled-check so a STRAGGLER finishing after the batch already settled still records
     *    its `COMPLETED`/`FAILED` (it just does not re-wake).
     *  - The `settledAt` write under the held lock is the exactly-once parent-resume gate; a later settler sees it set and the
     *    conditional UPDATE matches 0 rows.
     */
    public function claimParentResumeOnChildTerminal(
        AIConversation $childConversation,
        string $childRunToken,
        string $terminalRunState,
        ?int $lastSeenMessageId = null
    ): AgentDelegationResumeClaim {
        $batchId = $childConversation->delegationBatchId ?? null;
        $childId = $childConversation->id ?? null;
        if ($batchId === null || $childId === null) {
            return AgentDelegationResumeClaim::nothingDecided();
        }
        $messagesTable = DBAIConversationMessageModel::getTableName();

        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationsTable = DBAIConversationModel::getTableName();
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();

        // READ COMMITTED for THIS transaction only — every read sees latest committed (see the method docblock).
        $this->transactionsService()->beginReadCommittedTransaction($connection);
        try {
            // Lock-first: take the batch-row lock and read the wake parameters before any count.
            $batchRow = $connection->fetchAssociative(
                'SELECT `delegateWakeCondition`, `quorumSuccessThreshold`, `expectedDelegationCount`, `settledAt`, `foldedAt`'
                . " FROM `$batchTable` WHERE `id` = :batchId FOR UPDATE",
                ['batchId' => $batchId]
            );
            if ($batchRow === false) {
                $connection->commit();
                return AgentDelegationResumeClaim::nothingDecided();
            }

            // (a) the child goes terminal EXACTLY ONCE — token-guarded, the SOLE writer of its terminal state — and
            // ONLY if no inbound USER message was accepted after the worker's last look at the tail (the
            // accepted-input generation): a continuation that landed in the window between that look and this
            // statement must not be buried under a terminal row the claim refuses forever. `$lastSeenMessageId` is
            // the newest message the worker derived from; null = no fence (legacy callers).
            // Accepted input = a USER message or an injected SIGNAL (a continuation hand-over) newer than the last derive.
            $fence = $lastSeenMessageId !== null
                ? " AND NOT EXISTS (SELECT 1 FROM `$messagesTable` `m` WHERE `m`.`aiConversationId` = :childId AND (`m`.`role` = :userRole OR `m`.`messageType` = :signalType) AND `m`.`id` > :lastSeenMessageId)"
                : '';
            $parameters = [
                'terminal' => $terminalRunState,
                'childId' => $childId,
                'token' => $childRunToken,
                'running' => AIConversationRunState::RUNNING,
            ];
            if ($lastSeenMessageId !== null) {
                $parameters['userRole'] = AIConversationMessage::ROLE_USER;
                $parameters['signalType'] = AIConversationMessage::MESSAGE_SIGNAL;
                $parameters['lastSeenMessageId'] = $lastSeenMessageId;
            }
            $terminalRows = (int)$connection->executeStatement(
                "UPDATE `$conversationsTable` SET `runState` = :terminal, `runToken` = NULL"
                . ' WHERE `id` = :childId AND `runToken` = :token AND `runState` = :running' . $fence,
                $parameters
            );
            if ($terminalRows !== 1) {
                $connection->rollBack();
                $childRow = $connection->fetchAssociative(
                    "SELECT `runToken`, `runState` FROM `$conversationsTable` WHERE `id` = :childId",
                    ['childId' => $childId]
                );
                if ($childRow === false) {
                    return AgentDelegationResumeClaim::childTerminalLost(false, false);
                }
                // lost the claim / redelivery — another worker owns this child (or the fence refused)
                return AgentDelegationResumeClaim::childTerminalLost(
                    // Still ours and still RUNNING = the fence (not a lost claim) refused: an accepted continuation is
                    // waiting — the caller yields (release IDLE + resume) instead of settling a stale verdict.
                    $lastSeenMessageId !== null
                        && $childRow['runToken'] === $childRunToken
                        && $childRow['runState'] === AIConversationRunState::RUNNING,
                    // Already terminal (a timeout / a QUORUM cancellation cleared the token first): nobody else will
                    // publish this child's verdict — the caller may still publish its slice.
                    AIConversationRunState::of((string)$childRow['runState'])->isTerminal()
                );
            }

            // Already settled (by siblings / a timeout): keep the child terminal we just recorded. A COMPLETED verdict
            // arriving after the settle (an explicitly continued child, a straggler with a real result) re-opens the
            // batch's fold obligation UNDER THE LOCK — never earlier, so no stale results map is folded meanwhile — and
            // re-wakes the parent; a late FAILED / CANCELLED does not. Not a fresh settle: the caller cancels no losers
            // and marks nothing completed.
            if ($batchRow['settledAt'] !== null) {
                if ($terminalRunState === AIConversationRunState::COMPLETED) {
                    $connection->executeStatement("UPDATE `$batchTable` SET `foldedAt` = NULL WHERE `id` = :batchId", ['batchId' => $batchId]);
                    $connection->commit();
                    return AgentDelegationResumeClaim::recordedAfterTheSettle(true);
                }
                $connection->commit();
                return AgentDelegationResumeClaim::recordedAfterTheSettle(false);
            }

            // (c) claim the wake exactly once — the derived counts run in the conditional UPDATE under the held lock.
            // BACKGROUND: join math = ALL; the batch settles normally but the CALLER
            // never dispatches a parent resume for it — the settled-unfolded state is picked up by the NEXT
            // genuinely-running parent turn (a compact note + foldedAt stamp there), never by an auto-drive.
            $isAllCondition = $batchRow['delegateWakeCondition'] === AIConversationDelegationResumeCondition::ALL
                || $batchRow['delegateWakeCondition'] === AIConversationDelegationResumeCondition::BACKGROUND;
            $effectiveQuorum = $isAllCondition
                ? 0
                : ($batchRow['delegateWakeCondition'] === AIConversationDelegationResumeCondition::QUORUM
                    ? (int)$batchRow['quorumSuccessThreshold']
                    : 1);
            // The branch of the resume condition is chosen here and not by a bound flag inside the statement: a
            // statement binds only the parameters it names.
            $childCountOfBatchSql = "(SELECT COUNT(*) FROM `$conversationsTable` WHERE `delegationBatchId` = :batchId AND `runState`";
            $settleParameters = [
                'now' => (new DateTime())->format('Y-m-d H:i:s'),
                'batchId' => $batchId,
                'completed' => AIConversationRunState::COMPLETED,
                'failed' => AIConversationRunState::FAILED,
                'expected' => (int)$batchRow['expectedDelegationCount'],
            ];
            if ($isAllCondition) {
                // every expected child has ended
                $resumeConditionSql = $childCountOfBatchSql . ' IN (:completed, :failed, :cancelled)) >= :expected';
                $settleParameters['cancelled'] = AIConversationRunState::CANCELLED;
            } else {
                // enough children completed, or so many failed that the threshold cannot be reached any more
                $resumeConditionSql = '(' . $childCountOfBatchSql . ' = :completed) >= :quorum'
                    . ' OR :expected - ' . $childCountOfBatchSql . ' = :failed) < :quorum)';
                $settleParameters['quorum'] = $effectiveQuorum;
            }
            $settledRows = (int)$connection->executeStatement(
                "UPDATE `$batchTable` SET `settledAt` = :now WHERE `id` = :batchId AND `settledAt` IS NULL AND " . $resumeConditionSql,
                $settleParameters
            );
            $connection->commit();
            return AgentDelegationResumeClaim::recordedOnTheOpenBatch($settledRows === 1);
        } catch (Throwable $settleError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                // A COMMIT (or the in-try rollBack) that throws has already zeroed DBAL's nesting counter
                // (Connection::commit decrements in a finally; rollBack zeroes before the driver call), so no
                // rollBack() can reach the driver anymore — close() clears a possibly orphaned server-side
                // transaction on this long-lived worker connection (which would otherwise pin the batch-row
                // FOR UPDATE lock and poison every later beginTransaction). Auto-reconnect on next use.
                $connection->close();
            }
            throw $settleError;
        }
    }

    /**
     * Batch ids that have TIMED OUT — unsettled past their absolute {@see AIConversationDelegationBatch::$deadlineAt}
     * (the reaper). The reaper force-fails every non-terminal member of each, then re-runs the settle so an `ALL`
     * barrier (or a `QUORUM` that can no longer be met) completes instead of hanging on a crashed/starved child.
     *
     * @return int[]
     */
    public function findTimedOutUnfinishedBatchIds(): array
    {
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();
        $conversationsTable = DBAIConversationModel::getTableName();
        $environment = AIConversationsService::currentEnvironment();
        // only batches of this environment's parents — production shares the database with every dev workspace
        $ids = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            "SELECT `batch`.`id` FROM `$batchTable` `batch`"
            . " JOIN `$conversationsTable` `parentConversation` ON `parentConversation`.`id` = `batch`.`parentConversationId`"
            . ' WHERE `batch`.`settledAt` IS NULL AND `batch`.`deadlineAt` IS NOT NULL AND `batch`.`deadlineAt` < :now'
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate('parentConversation', $environment),
            ['now' => (new DateTime())->format('Y-m-d H:i:s'), 'environment' => $environment]
        );
        return array_map('intval', $ids);
    }

    /**
     * Parent-conversation ids of batches that are SETTLED but the parent has not yet FOLDED (the reaper backstop):
     * the wake dispatch was lost in the crash window between the settle commit and the resume dispatch. The reaper
     * re-fires the parent resume for these. ONLY parents parked AWAITING_DELEGATES or released IDLE — the states
     * where a fold is owed and nobody is computing. A RUNNING parent folds in its own loop, and a TERMINAL parent
     * (FAILED — e.g. expired past the revival grace window — / COMPLETED / CANCELLED) must NOT be re-fired, else the
     * cron re-pokes it forever.
     *
     * @return int[]
     */
    public function findParentConversationIdsWithUnreadChildResults(): array
    {
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();
        $conversationsTable = DBAIConversationModel::getTableName();
        $ids = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            "SELECT DISTINCT `batch`.`parentConversationId` FROM `$batchTable` `batch`"
            . " JOIN `$conversationsTable` `parentConversation` ON `parentConversation`.`id` = `batch`.`parentConversationId`"
            . ' WHERE `batch`.`settledAt` IS NOT NULL AND `batch`.`foldedAt` IS NULL'
            . ' AND `batch`.`delegateWakeCondition` <> :background'
            . ' AND `parentConversation`.`runState` IN (:awaiting, :idle)'
            // only this environment's parents — production shares the database with every dev workspace
            . ' AND ' . DBAIConversations::buildEnvironmentPredicate('parentConversation', AIConversationsService::currentEnvironment()),
            [
                'awaiting' => AIConversationRunState::AWAITING_DELEGATES,
                'idle' => AIConversationRunState::IDLE,
                'background' => AIConversationDelegationResumeCondition::BACKGROUND,
                'environment' => AIConversationsService::currentEnvironment(),
            ]
        );
        return array_map('intval', $ids);
    }

    /**
     * Ids of a batch's still-active (RUNNING/QUEUED) member conversations — the reaper force-fails these on timeout,
     * and the QUORUM/ANY cancellation signals these. Raw, worker-side (no auth) by design.
     *
     * @return int[]
     */
    public function findActiveMemberConversationIds(int $batchId): array
    {
        $conversationsTable = DBAIConversationModel::getTableName();
        $ids = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            "SELECT `id` FROM `$conversationsTable` WHERE `delegationBatchId` = :batchId AND `runState` IN (:running, :queued)",
            ['batchId' => $batchId, 'running' => AIConversationRunState::RUNNING, 'queued' => AIConversationRunState::QUEUED]
        );
        return array_map('intval', $ids);
    }

    /**
     * Batches by id (the run's MEMBER batches, loaded from their membership rows). Empty set for
     * no ids; the set repo applies the batch rights.
     */
    public function findBatchesByIds(array $batchIds): AIConversationDelegationBatches
    {
        $batchIds = array_values(array_unique(array_map('intval', $batchIds)));
        if ($batchIds === []) {
            return AIConversationDelegationBatches::newInstance();
        }
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $entitySetRepo::createQueryBuilder();
        $queryBuilder->andWhere("$alias.id IN (:batchIds)")
            ->setParameter('batchIds', $batchIds)
            ->orderBy("$alias.id", 'ASC');
        return $entitySetRepo->find($queryBuilder, useEntityRegistrCache: false) ?? AIConversationDelegationBatches::newInstance();
    }

    /**
     * Per batch: how many child conversations are still EXECUTABLE (IDLE = dispatched but unclaimed or yielded — a
     * finished child never returns to IDLE since the terminal-child claim guard; QUEUED / RUNNING / AWAITING_DELEGATES)
     * and how many are terminal (COMPLETED / FAILED / CANCELLED). ONE query for all batches.
     * Raw, rights-agnostic (the caller already authorised the run).
     *
     * @param int[] $batchIds
     * @return AgentDelegationChildCounts[]
     */
    public function countChildrenByRunStateForBatches(array $batchIds): array
    {
        $batchIds = array_values(array_unique(array_map('intval', $batchIds)));
        $childCountByRunStateByBatchId = [];
        foreach ($batchIds as $batchId) {
            $childCountByRunStateByBatchId[$batchId] = [];
        }
        if ($batchIds === []) {
            return [];
        }
        $conversationsTable = DBAIConversationModel::getTableName();
        $rows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            "SELECT `delegationBatchId`, `runState`, COUNT(*) AS `childCount` FROM `$conversationsTable`"
            . ' WHERE `delegationBatchId` IN (:batchIds) GROUP BY `delegationBatchId`, `runState`',
            ['batchIds' => $batchIds],
            ['batchIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        foreach ($rows as $row) {
            $batchId = (int)$row['delegationBatchId'];
            $runState = (string)$row['runState'];
            $childCountByRunStateByBatchId[$batchId][$runState] = ($childCountByRunStateByBatchId[$batchId][$runState] ?? 0) + (int)$row['childCount'];
        }
        return array_map(
            static fn (array $childCountByRunState): AgentDelegationChildCounts => AgentDelegationChildCounts::fromChildCountByRunState($childCountByRunState),
            $childCountByRunStateByBatchId
        );
    }

    /**
     * All batches a conversation forked (by parentConversationId), oldest first — for the inspector's batch section
     * (the lazy `forkedDelegationBatches` inverse relation doesn't resolve in the CLI, so query it explicitly).
     */
    public function findBatchesForParent(int $parentConversationId): ?AIConversationDelegationBatches
    {
        $entitySetRepo = $this->getEntitySetRepoClassInstance();
        $alias = $entitySetRepo::getBaseModelAlias();
        $queryBuilder = $entitySetRepo::createQueryBuilder();
        $queryBuilder->andWhere("$alias.parentConversationId = :parentId")
            ->setParameter('parentId', $parentConversationId)
            ->orderBy("$alias.id", 'ASC');
        return $entitySetRepo->find($queryBuilder);
    }

    /**
     * The batch's member conversations as entities (admin inspector / fold). Rights-scoped via DBAIConversation.
     */
    public function findBatchMembers(AIConversationDelegationBatch $batch): ?AIConversations
    {
        if (!isset($batch->id)) {
            return null;
        }
        $entitySetRepoClassInstance = AIConversations::getService()->getEntitySetRepoClassInstance();
        $alias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$alias.delegationBatchId = :batchId")
            ->setParameter('batchId', $batch->id);
        /** @var AIConversations|null $members */
        $members = $entitySetRepoClassInstance->find($queryBuilder);
        return $members;
    }

    /**
     * The id of the oldest SETTLED-but-unfolded batch this conversation forked (the parent-derive trigger): the
     * orchestrator should drive a turn to FOLD its verdicts. Raw, rights-agnostic (worker context). Null when none.
     */
    public function findOldestBatchIdWithUnreadChildResultsForParent(int $parentConversationId): ?int
    {
        // BACKGROUND batches never OWE a fold turn — they are excluded here (and from the reaper re-fire) so a
        // settled background job cannot auto-drive the parent; their note rides the next genuine turn
        // ({@see findSettledUnfoldedBackgroundBatchIdsForParent}).
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();
        $id = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            "SELECT `id` FROM `$batchTable` WHERE `parentConversationId` = :parentId"
            . ' AND `settledAt` IS NOT NULL AND `foldedAt` IS NULL AND `delegateWakeCondition` <> :background'
            . ' ORDER BY `id` ASC LIMIT 1',
            [
                'parentId' => $parentConversationId,
                'background' => AIConversationDelegationResumeCondition::BACKGROUND,
            ]
        );
        return $id === false ? null : (int)$id;
    }

    /**
     * The settled-but-unnoted BACKGROUND batches of a parent: finished
     * background work whose completion note has not yet ridden a turn. The caller injects ONE compact note and
     * marks them read ({@see markChildResultsReadByParent}) — they never owe a fold turn and never auto-drive.
     *
     * @return int[]
     */
    public function findSettledUnfoldedBackgroundBatchIdsForParent(int $parentConversationId): array
    {
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();
        $ids = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            "SELECT `id` FROM `$batchTable` WHERE `parentConversationId` = :parentId"
            . ' AND `settledAt` IS NOT NULL AND `foldedAt` IS NULL AND `delegateWakeCondition` = :background'
            . ' ORDER BY `id` ASC',
            [
                'parentId' => $parentConversationId,
                'background' => AIConversationDelegationResumeCondition::BACKGROUND,
            ]
        );
        return array_map('intval', $ids);
    }

    /**
     * The `delegate` call messages of a parent conversation whose row status is still PENDING / RUNNING although
     * the batch their children belong to has SETTLED — anchored through the CHILDREN (`delegatedFromMessageId` →
     * `delegationBatchId`), the one anchor every spawn path writes (a batch's own `spawnedByMessageId` is the prose
     * head for a model-issued delegate and NULL for a tool-declared fan-out). Set-based over the whole
     * conversation, not the loaded message window — a stale call below the compaction cover is found too. The
     * feed of the turn-head repair.
     *
     * @return int[]
     * @throws \Doctrine\DBAL\Exception
     */
    public function findUnresolvedDelegateCallMessageIdsWithSettledBatch(int $parentConversationId): array
    {
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();
        $conversationsTable = DBAIConversationModel::getTableName();
        $messagesTable = DBAIConversationMessageModel::getTableName();
        $ids = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            "SELECT DISTINCT `m`.`id` FROM `$messagesTable` `m`"
            . " JOIN `$conversationsTable` `c` ON `c`.`delegatedFromMessageId` = `m`.`id`"
            . " JOIN `$batchTable` `b` ON `b`.`id` = `c`.`delegationBatchId`"
            . ' WHERE `m`.`aiConversationId` = :parentId AND `b`.`settledAt` IS NOT NULL'
            . ' AND ' . EntityManagerFactory::getDialect()->jsonFieldText('`m`.`toolCall`', 'name') . ' = :delegateName'
            . ' AND ' . EntityManagerFactory::getDialect()->jsonFieldText('`m`.`toolCall`', 'status') . ' IN (:pending, :running)',
            [
                'parentId' => $parentConversationId,
                'delegateName' => DelegateTool::NAME,
                'pending' => AIConversationMessage::STATUS_PENDING,
                'running' => AIConversationMessage::STATUS_RUNNING,
            ]
        );
        return array_map('intval', $ids);
    }

    /**
     * The terminal child-state tally of a batch (the fold signal): how many of its delegation children reached
     * COMPLETED / FAILED / CANCELLED. Feeds {@see AIConversationDelegationBatch::buildChildrenFinishedSignalText()} so the
     * DELEGATIONS_SETTLED signal can state "5 completed, 1 cancelled". Raw GROUP BY, rights-agnostic (worker context).
     */
    public function countChildrenByTerminalState(int $batchId): AgentDelegationChildCounts
    {
        $conversationsTable = DBAIConversationModel::getTableName();
        $rows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            "SELECT `runState`, COUNT(*) AS `terminalCount` FROM `$conversationsTable` WHERE `delegationBatchId` = :batchId"
            . ' AND `runState` IN (:completed, :failed, :cancelled) GROUP BY `runState`',
            [
                'batchId' => $batchId,
                'completed' => AIConversationRunState::COMPLETED,
                'failed' => AIConversationRunState::FAILED,
                'cancelled' => AIConversationRunState::CANCELLED,
            ]
        );
        $childCountByRunState = [];
        foreach ($rows as $row) {
            $childCountByRunState[(string)$row['runState']] = (int)$row['terminalCount'];
        }
        return AgentDelegationChildCounts::fromChildCountByRunState($childCountByRunState);
    }

    /**
     * Whether a sub-agent of this conversation has a run that has not ended: an unended deadline row whose
     * `delegate` row carries a sub-agent record. Legacy batches never match. Raw, rights-agnostic (worker context).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function hasUnendedSubAgentRunOfParent(int $parentConversationId): bool
    {
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '` `deadline`'
            . ' JOIN `' . DBAIConversationMessageModel::getTableName() . '` `delegateCall` ON `delegateCall`.`id` = `deadline`.`spawnedByMessageId`'
            . ' WHERE `deadline`.`parentConversationId` = :parentId AND `deadline`.`settledAt` IS NULL'
            . ' AND ' . self::buildDelegateCallCarriesSubAgentRecordSql(),
            ['parentId' => $parentConversationId]
        ) > 0;
    }

    /**
     * Whether this conversation has a sub-agent run that has not ended and resumes it when it ends: an unended deadline
     * row (one per run of a sub-agent of the async path) or an unsettled legacy batch it forked,
     * never a BACKGROUND one. The release keeps the parent's status RUNNING and the settled-turn follow-ups wait while it
     * holds. Raw, rights-agnostic (worker context).
     */
    public function hasUnfinishedBatchForParent(int $parentConversationId): bool
    {
        // BACKGROUND batches never park the parent — an unfinished background job must not hold the
        // conversation in AWAITING_DELEGATES.
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM `$batchTable` WHERE `parentConversationId` = :parentId AND `settledAt` IS NULL"
            . ' AND `delegateWakeCondition` <> :background',
            [
                'parentId' => $parentConversationId,
                'background' => AIConversationDelegationResumeCondition::BACKGROUND,
            ]
        ) > 0;
    }

    /**
     * Time out a batch past its deadline (the reaper). The deadline is the SETTLE
     * AUTHORITY, not a re-test of the resume condition: once no member is alive, force-fail every NON-terminal member
     * and settle UNCONDITIONALLY — so an orphaned / over-counted batch (`expectedDelegationCount` > the terminal
     * child count, e.g. a repeat-guarded delegate that never spawned a child) can NEVER deadlock the parent. This is
     * what makes such a stuck batch recoverable.
     *
     * HEARTBEAT-AWARE: a RUNNING member with a FRESH heartbeat is a long tool call that merely
     * outran the deadline, NOT a dead worker — EXTEND the deadline by another window and leave it running rather than
     * discarding live work; the deadline reaps the DEAD, not the slow.
     *
     * Returns the parentConversationId to wake when it settles, else null (already settled / extended / nothing to do).
     * Lock-first, READ COMMITTED. Caller (reaper / customer-reload revive) has rights deactivated and impersonates the
     * parent for the dispatch.
     */
    public function timeoutBatch(int $batchId): ?int
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationsTable = DBAIConversationModel::getTableName();
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();

        // Never kill a live child: a RUNNING member with a fresh heartbeat extends the deadline instead.
        if ($this->extendDeadlineWhileAMemberIsAlive($batchId)) {
            return null;
        }
        // The deadline row of ONE sub-agent run ends that run TIMED_OUT and tells the parent through the
        // run-end notification — the reaper resumes no parent for it. A legacy batch takes the path below.
        $subAgentDelegateCallRow = $this->findSubAgentDelegateCallRowOfDeadline($batchId);
        if ($subAgentDelegateCallRow !== null) {
            // A run still QUEUED behind the tree's capacity waits for a slot, it is not dead: its own deadline row is
            // extended (one batch used to cover all siblings, so a running sibling's heartbeat kept it open).
            if ($this->extendDeadlineWhileTheMemberWaitsForASlot($batchId)) {
                return null;
            }
            $this->timeOutSubAgentRun($batchId, $subAgentDelegateCallRow);
            return null;
        }

        // No live member remains → genuinely timing out. Mark each still-active member's delegate-call FAILED so the
        // fold reports a settled timeout, not a phantom RUNNING. This loop only runs when the batch is actually timing
        // out. The relation is tested with isset(), which never loads it: the marking applies only to a member whose
        // delegate call a caller has set on it.
        foreach ($this->findActiveMemberConversationIds($batchId) as $memberConversationId) {
            $member = AIConversation::getService()->find($memberConversationId);
            $delegateCall = isset($member->delegatedFromMessage) ? $member->delegatedFromMessage : null;
            if ($delegateCall !== null && isset($delegateCall->toolCall) && !$delegateCall->toolCall->isResolved()) {
                $delegateCall->toolCall->markFailed();
                $delegateCall->toolCall->result = 'Delegation timed out (batch deadline exceeded).';
                // targeted single-column persist of `toolCall`.
                /** @var AIConversationMessagesService $aIConversationMessagesService */
                $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
                $aIConversationMessagesService->persistToolCall($delegateCall);
            }
        }

        $this->transactionsService()->beginReadCommittedTransaction($connection);
        try {
            $batchRow = $connection->fetchAssociative(
                "SELECT `parentConversationId`, `settledAt` FROM `$batchTable` WHERE `id` = :batchId FOR UPDATE",
                ['batchId' => $batchId]
            );
            if ($batchRow === false || $batchRow['settledAt'] !== null) {
                $connection->commit();
                return null;
            }
            // Force-fail EVERY non-terminal member: RUNNING (all stale now), IDLE (dispatched but
            // never claimed — the reaper-invisible orphan), QUEUED (never promoted). All three blocked the settle.
            $connection->executeStatement(
                "UPDATE `$conversationsTable` SET `runState` = :failed, `runToken` = NULL"
                . ' WHERE `delegationBatchId` = :batchId AND `runState` IN (:running, :idle, :queued)',
                [
                    'failed' => AIConversationRunState::FAILED,
                    'batchId' => $batchId,
                    'running' => AIConversationRunState::RUNNING,
                    'idle' => AIConversationRunState::IDLE,
                    'queued' => AIConversationRunState::QUEUED,
                ]
            );
            // The deadline SETTLES unconditionally. No member is in flight, so "stop waiting": the
            // `COUNT(*) >= expected` gate is DROPPED (that gate is exactly what let an over-counted/orphaned batch
            // hang forever — the reaper re-applying it was a no-op). The deadline alone is the settle authority.
            $settledRows = (int)$connection->executeStatement(
                "UPDATE `$batchTable` SET `settledAt` = :now WHERE `id` = :batchId AND `settledAt` IS NULL",
                ['now' => (new DateTime())->format('Y-m-d H:i:s'), 'batchId' => $batchId]
            );
            $connection->commit();
            return $settledRows === 1 && $batchRow['parentConversationId'] !== null
                ? (int)$batchRow['parentConversationId']
                : null;
        } catch (Throwable $timeoutError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                // COMMIT threw → DBAL counter already 0 → rollBack() unreachable; close() clears a possibly
                // orphaned server-side transaction (same rationale as in claimParentResumeOnChildTerminal's catch).
                $connection->close();
            }
            throw $timeoutError;
        }
    }

    /**
     * The live-member rule of the deadline: a RUNNING member whose heartbeat is FRESHER than the hung threshold is still
     * computing (a long Argus call that outran the deadline) — the deadline is extended by another full window (atomic,
     * only while unsettled). Returns whether it was extended; the deadline reaps the dead, not the slow.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function extendDeadlineWhileAMemberIsAlive(int $batchId): bool
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $hungBefore = (new DateTime())->modify('-' . AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');
        $liveRunningMembers = (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationModel::getTableName() . '` WHERE `delegationBatchId` = :batchId'
            . ' AND `runState` = :running AND `runHeartbeatAt` IS NOT NULL AND `runHeartbeatAt` >= :hungBefore',
            ['batchId' => $batchId, 'running' => AIConversationRunState::RUNNING, 'hungBefore' => $hungBefore]
        );
        if ($liveRunningMembers === 0) {
            return false;
        }
        $connection->executeStatement(
            'UPDATE `' . DBAIConversationDelegationBatchModel::getTableName() . '` SET `deadlineAt` = '
            . EntityManagerFactory::getDialect()->dateAdd(':now', '`timeoutSeconds`', 'SECOND')
            . ' WHERE `id` = :batchId AND `settledAt` IS NULL',
            ['now' => (new DateTime())->format('Y-m-d H:i:s'), 'batchId' => $batchId]
        );
        return true;
    }

    /**
     * A sub-agent run whose child is still QUEUED (no free slot in the tree yet) gets another window on its deadline row
     * — for at most {@see SUB_AGENT_QUEUED_MAX_WAIT_SECONDS} after the row was written. A queued child whose promotion
     * was lost (a sibling's end committed, its promotion never ran) would otherwise wait for ever; past the cap the
     * caller ends its run TIMED_OUT and the parent is told. Returns whether it was extended.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function extendDeadlineWhileTheMemberWaitsForASlot(int $deadlineId): bool
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $queuedMembers = (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationModel::getTableName() . '` WHERE `delegationBatchId` = :deadlineId AND `runState` = :queued',
            ['deadlineId' => $deadlineId, 'queued' => AIConversationRunState::QUEUED]
        );
        if ($queuedMembers === 0) {
            return false;
        }
        $now = new DateTime();
        $writtenSince = (clone $now)->modify('-' . self::SUB_AGENT_QUEUED_MAX_WAIT_SECONDS . ' seconds');
        return (int)$connection->executeStatement(
            'UPDATE `' . DBAIConversationDelegationBatchModel::getTableName() . '` SET `deadlineAt` = '
            . EntityManagerFactory::getDialect()->dateAdd(':now', '`timeoutSeconds`', 'SECOND')
            . ' WHERE `id` = :deadlineId AND `settledAt` IS NULL AND `created` > :writtenSince',
            ['now' => $now->format(DateTime::SIMPLE), 'deadlineId' => $deadlineId, 'writtenSince' => $writtenSince->format(DateTime::SIMPLE)]
        ) === 1;
    }

    /**
     * The `delegate` row of the sub-agent whose run this deadline row belongs to (`spawnedByMessageId` = the
     * sub-agent's row), when that row carries a sub-agent record; null for a legacy batch (no record, or a batch of a
     * prose head / a fan-out).
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    public function findSubAgentDelegateCallRowOfDeadline(int $deadlineId): ?AIConversationMessage
    {
        $spawnedByMessageId = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `spawnedByMessageId` FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '` WHERE `id` = :id',
            ['id' => $deadlineId]
        );
        if ($spawnedByMessageId === false || $spawnedByMessageId === null) {
            return null;
        }
        $delegateCallRow = $this->messagesService()->findStoredDelegateCallRow((int)$spawnedByMessageId);
        if ($delegateCallRow === null || !isset($delegateCallRow->delegationResults) || $delegateCallRow->delegationResults->isLegacyMap()) {
            return null;
        }
        return $delegateCallRow;
    }

    /**
     * The given batch ids plus the deadline rows of every OTHER run of the same sub-agents: a
     * continuation run gets its own deadline row on the same `delegate` row (`spawnedByMessageId`), and its child's
     * `delegationBatchId` moves there — a reader that knows only run 1's row (an application's member list) would lose
     * the child. Legacy batches (their spawning row carries no sub-agent record) stay as given. Raw, rights-agnostic (the
     * caller already authorised the run); ascending ids.
     *
     * @param int[] $batchIds
     * @return int[]
     * @throws \Doctrine\DBAL\Exception
     */
    public function findDeadlineIdsOfAllRunsOfTheSameSubAgents(array $batchIds): array
    {
        $batchIds = array_values(array_unique(array_map('intval', $batchIds)));
        if ($batchIds === []) {
            return [];
        }
        $siblingDeadlineIds = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT `siblingDeadline`.`id` FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '` `givenDeadline`'
            . ' JOIN `' . DBAIConversationMessageModel::getTableName() . '` `delegateCall` ON `delegateCall`.`id` = `givenDeadline`.`spawnedByMessageId`'
            . ' JOIN `' . DBAIConversationDelegationBatchModel::getTableName() . '` `siblingDeadline` ON `siblingDeadline`.`spawnedByMessageId` = `givenDeadline`.`spawnedByMessageId`'
            . ' WHERE `givenDeadline`.`id` IN (:batchIds)'
            . ' AND ' . self::buildDelegateCallCarriesSubAgentRecordSql(),
            ['batchIds' => $batchIds],
            ['batchIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $allDeadlineIds = array_values(array_unique(array_merge($batchIds, array_map('intval', $siblingDeadlineIds))));
        sort($allDeadlineIds);
        return $allDeadlineIds;
    }

    /**
     * The sub-agent record of each given deadline row whose spawning `delegate` row carries one, keyed by
     * deadline id; a legacy batch is absent from the result. One raw read past rights and the entity registry (the
     * children and the notifier write those rows from other processes) — for the operator views that label a deadline row
     * by its sub-agent and run (a timeline of the runs).
     *
     * @param int[] $deadlineIds
     * @return DelegationSubAgentRecord[]
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    public function findSubAgentRecordsOfDeadlines(array $deadlineIds): array
    {
        $deadlineIds = array_values(array_unique(array_map('intval', $deadlineIds)));
        if ($deadlineIds === []) {
            return [];
        }
        $deadlineRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `deadline`.`id` AS `deadlineId`, '
            . EntityManagerFactory::getDialect()->jsonText('`delegateCall`.`delegationResults`', JsonPath::fromKeys(DelegationResults::SUB_AGENT_KEY))
            . ' AS `subAgentRecord`'
            . ' FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '` `deadline`'
            . ' JOIN `' . DBAIConversationMessageModel::getTableName() . '` `delegateCall` ON `delegateCall`.`id` = `deadline`.`spawnedByMessageId`'
            . ' WHERE `deadline`.`id` IN (:deadlineIds)'
            . ' AND ' . self::buildDelegateCallCarriesSubAgentRecordSql(),
            ['deadlineIds' => $deadlineIds],
            ['deadlineIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $subAgentRecordByDeadlineId = [];
        foreach ($deadlineRows as $deadlineRow) {
            $storedRecord = is_string($deadlineRow['subAgentRecord'] ?? null)
                ? json_decode($deadlineRow['subAgentRecord'], true, 512, JSON_THROW_ON_ERROR)
                : null;
            if (is_array($storedRecord)) {
                $subAgentRecordByDeadlineId[(int)$deadlineRow['deadlineId']] = DelegationSubAgentRecord::fromStoredArray($storedRecord);
            }
        }
        return $subAgentRecordByDeadlineId;
    }

    /**
     * One sub-agent run past its deadline with no live member (`timeOutSubAgentRun`): in ONE transaction
     * (READ COMMITTED; the `delegate` row locked first, as the run end of the child locks it — the two writers of one run
     * serialize on it and never cross — then the deadline row) the child is force-failed (token cleared), the record's run ends
     * TIMED_OUT with the reason `timedOut`, and the deadline row is stamped ended — then the run end's one notification is
     * written into the parent. A deadline that already ended (the child's own end won) or that is not past any more (an
     * admission moved it) does nothing. Returns whether THIS call ended the run.
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \Throwable
     */
    public function timeOutSubAgentRun(int $deadlineId, AIConversationMessage $delegateCallRow): bool
    {
        $subAgentRecord = $delegateCallRow->delegationResults->subAgent;
        $runNumber = $subAgentRecord->currentRunNumber;
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $now = new DateTime();
        $this->transactionsService()->beginReadCommittedTransaction($connection);
        try {
            $this->messagesService()->lockStoredDelegateCallRow((int)$delegateCallRow->id);
            // The deadline is re-read under the lock — an admission that gave the run a new
            // window after the sweep's read (a child just admitted, not yet claimed) is never timed out
            $deadlineRow = $connection->fetchAssociative(
                'SELECT `settledAt` FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '` WHERE `id` = :deadlineId AND `deadlineAt` < :now FOR UPDATE',
                ['deadlineId' => $deadlineId, 'now' => $now->format(DateTime::SIMPLE)]
            );
            if ($deadlineRow === false || $deadlineRow['settledAt'] !== null) {
                $connection->commit();
                return false;
            }
            $childRow = $connection->fetchAssociative(
                'SELECT `id`, `runState` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `delegationBatchId` = :deadlineId LIMIT 1',
                ['deadlineId' => $deadlineId]
            );
            $childWasQueued = $childRow !== false && (string)$childRow['runState'] === AIConversationRunState::QUEUED;
            // an orchestrator's message the child never read is named in the run's end — the parent was
            // told it was delivered
            $timedOutChild = AIConversation::newInstance();
            $timedOutChild->id = $childRow !== false ? (int)$childRow['id'] : null;
            $unreadMessageNote = $childRow !== false && $this->messagesService()->hasOrchestratorMessageWaitingForAModelCall($timedOutChild)
                ? '; your message to it was not read'
                : '';
            $connection->executeStatement(
                'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runState` = :failed, `runToken` = NULL'
                . ' WHERE `delegationBatchId` = :deadlineId AND `runState` IN (:unfinishedRunStates)',
                [
                    'failed' => AIConversationRunState::FAILED,
                    'deadlineId' => $deadlineId,
                    'unfinishedRunStates' => [AIConversationRunState::RUNNING, AIConversationRunState::IDLE, AIConversationRunState::QUEUED],
                ],
                ['unfinishedRunStates' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
            $timedOutRun = DelegationSubAgentRun::ended(
                $runNumber,
                DelegationSubAgentRun::STATUS_TIMED_OUT,
                $now,
                null,
                DelegationSubAgentRun::FAILURE_REASON_TIMED_OUT,
                ($childWasQueued
                    ? 'no free slot within ' . DelegationSubAgentRun::formatDuration(self::SUB_AGENT_QUEUED_MAX_WAIT_SECONDS)
                    : 'no step before the deadline of ' . DelegationSubAgentRun::formatDuration(max(1, (int)($this->findTimeoutSecondsOfDeadline($deadlineId) ?? 300))))
                . $unreadMessageNote,
                null
            );
            $runEndWritten = $this->messagesService()->writeSubAgentRunEnded((int)$delegateCallRow->id, $timedOutRun);
            $this->markSubAgentRunDeadlineEnded($deadlineId, $now);
            $connection->commit();
        } catch (\Throwable $timeoutError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                $connection->close();
            }
            throw $timeoutError;
        }
        if ($runEndWritten) {
            $this->delegationNotificationsService()->notifyParentOfSubAgentRunEnd((int)$delegateCallRow->id, $runNumber);
        }
        return $runEndWritten;
    }

    /** The deadline row's window in seconds; null when the row is gone. */
    protected function findTimeoutSecondsOfDeadline(int $deadlineId): ?int
    {
        $timeoutSeconds = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `timeoutSeconds` FROM `' . DBAIConversationDelegationBatchModel::getTableName() . '` WHERE `id` = :id',
            ['id' => $deadlineId]
        );
        return $timeoutSeconds === false || $timeoutSeconds === null ? null : (int)$timeoutSeconds;
    }

    /**
     * The condition "the `delegate` row joined as `delegateCall` carries a sub-agent record": the key is there, whatever
     * it holds. A legacy row (a map of verdicts, or no document) carries none.
     */
    protected static function buildDelegateCallCarriesSubAgentRecordSql(): string
    {
        return EntityManagerFactory::getDialect()->jsonType('`delegateCall`.`delegationResults`', JsonPath::fromKeys(DelegationResults::SUB_AGENT_KEY))
            . ' IS NOT NULL';
    }

    /** The transactions whose statements read the latest commits — resolved inline (DI rule). */
    protected function transactionsService(): AIConversationTransactionsService
    {
        /** @var AIConversationTransactionsService $aIConversationTransactionsService */
        $aIConversationTransactionsService = DDDService::instance()->getService(AIConversationTransactionsService::class);
        return $aIConversationTransactionsService;
    }

    /** The messages service — resolved inline (DI rule). */
    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** The run-end notifier — resolved inline (DI rule). */
    protected function delegationNotificationsService(): AIConversationDelegationNotificationsService
    {
        /** @var AIConversationDelegationNotificationsService $aIConversationDelegationNotificationsService */
        $aIConversationDelegationNotificationsService = DDDService::instance()->getService(AIConversationDelegationNotificationsService::class);
        return $aIConversationDelegationNotificationsService;
    }

    /**
     * The supersede ("QUEUED / IDLE → CANCELLED"): the runs of these deadline rows whose child
     * has NOT started yet (QUEUED behind the capacity, or admitted and not yet claimed) are cancelled — newer work replaced
     * them (the children of a superseded request). Per deadline row, in ONE transaction (READ COMMITTED; the
     * `delegate` row locked first, as every end of the run locks it): the child CANCELLED (token and owed stamp cleared,
     * guarded on not started — a RUNNING child finishes its turn), the record's run CANCELLED `superseded`, the deadline
     * row ended — then the run's one notification, which never resumes the parent ({@see \DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationRunEndNotification}).
     * A legacy batch (no sub-agent record) keeps the legacy cancel: its not-started members CANCELLED and the batch
     * settled and marked read ({@see settleAndFoldAfterCancellation()}). The freed slots are offered to the tree's queue.
     * The one AI-side method of the supersede (no AI SQL in an application's domain). Returns how many children
     * were cancelled.
     *
     * @param int[] $deadlineIds
     * @throws Throwable
     */
    public function cancelQueuedSubAgentRunsOfDeadlines(array $deadlineIds): int
    {
        $cancelledCount = 0;
        $rootConversationIdsWithFreedSlots = [];
        foreach (array_values(array_unique(array_map('intval', $deadlineIds))) as $deadlineId) {
            $subAgentDelegateCallRow = $this->findSubAgentDelegateCallRowOfDeadline($deadlineId);
            $cancelledOfDeadline = $subAgentDelegateCallRow === null
                ? $this->cancelNotStartedMembersOfLegacyBatch($deadlineId)
                : $this->cancelNotStartedSubAgentRun($deadlineId, $subAgentDelegateCallRow);
            if ($cancelledOfDeadline > 0 && $subAgentDelegateCallRow?->aiConversationId !== null) {
                $rootConversationIdsWithFreedSlots[(int)$subAgentDelegateCallRow->aiConversationId] = true;
            }
            $cancelledCount += $cancelledOfDeadline;
        }
        foreach (array_keys($rootConversationIdsWithFreedSlots) as $rootConversationId) {
            $this->delegationSpawnService()->promoteQueuedDelegations((int)$rootConversationId);
        }
        return $cancelledCount;
    }

    /**
     * One sub-agent run of {@see cancelQueuedSubAgentRunsOfDeadlines()}: 1 when its not-started child was cancelled and the
     * run ended CANCELLED `superseded` (then notified), 0 when the child had started or the run had ended already.
     *
     * @throws Throwable
     */
    protected function cancelNotStartedSubAgentRun(int $deadlineId, AIConversationMessage $delegateCallRow): int
    {
        $runNumber = $delegateCallRow->delegationResults->subAgent->currentRunNumber;
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $now = new DateTime();
        $this->transactionsService()->beginReadCommittedTransaction($connection);
        try {
            $this->messagesService()->lockStoredDelegateCallRow((int)$delegateCallRow->id);
            $cancelledChildren = $this->cancelNotStartedChildrenOfDeadline($deadlineId);
            if ($cancelledChildren === 0) {
                $connection->commit();
                return 0;
            }
            $runEndWritten = $this->messagesService()->writeSubAgentRunEnded((int)$delegateCallRow->id, DelegationSubAgentRun::ended(
                $runNumber,
                DelegationSubAgentRun::STATUS_CANCELLED,
                $now,
                null,
                DelegationSubAgentRun::FAILURE_REASON_SUPERSEDED,
                'newer work replaced it before it started',
                null
            ));
            $this->markSubAgentRunDeadlineEnded($deadlineId, $now);
            $connection->commit();
        } catch (Throwable $cancelError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                $connection->close();
            }
            throw $cancelError;
        }
        if ($runEndWritten) {
            $this->delegationNotificationsService()->notifyParentOfSubAgentRunEnd((int)$delegateCallRow->id, $runNumber);
        }
        return $cancelledChildren;
    }

    /**
     * The legacy cancel of a batch written before one deadline row per sub-agent run existed (one row, many members): its not-started members CANCELLED,
     * then the batch settled and marked read. Returns how many members were cancelled.
     *
     * @throws Throwable
     */
    protected function cancelNotStartedMembersOfLegacyBatch(int $batchId): int
    {
        $cancelledMembers = $this->cancelNotStartedChildrenOfDeadline($batchId);
        $this->settleAndFoldAfterCancellation($batchId);
        return $cancelledMembers;
    }

    /**
     * The children of a deadline row (a legacy batch: its members) that have NOT started — QUEUED behind the capacity or
     * admitted and not yet claimed — CANCELLED, their token and owed stamp cleared; a RUNNING child is never touched.
     * Returns how many were cancelled.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function cancelNotStartedChildrenOfDeadline(int $deadlineId): int
    {
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runState` = :cancelled, `runToken` = NULL, `agentTurnOwedAt` = NULL'
            . ' WHERE `delegationBatchId` = :deadlineId AND `runState` IN (:notStarted)',
            ['cancelled' => AIConversationRunState::CANCELLED, 'deadlineId' => $deadlineId, 'notStarted' => AIConversationRunState::DELEGATION_CHILD_NOT_STARTED],
            ['notStarted' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }

    /** The spawn (the admission of queued children into freed slots) — resolved inline (DI rule). */
    protected function delegationSpawnService(): AIConversationDelegationSpawnService
    {
        /** @var AIConversationDelegationSpawnService $aIConversationDelegationSpawnService */
        $aIConversationDelegationSpawnService = DDDService::instance()->getService(AIConversationDelegationSpawnService::class);
        return $aIConversationDelegationSpawnService;
    }

    /**
     * Re-run the end of a batch (`settledAt`) whose members were CANCELLED outside a child's own finish (the pending
     * children of a superseded request) and mark it read (`foldedAt`): an ALL barrier that counts the cancelled
     * members completes now instead of hanging to its deadline, and the parent is NOT resumed to take a superseded
     * request's instructions into its successor. Returns whether THIS call ended the batch.
     */
    public function settleAndFoldAfterCancellation(int $batchId): bool
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationsTable = DBAIConversationModel::getTableName();
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();
        $this->transactionsService()->beginReadCommittedTransaction($connection);
        try {
            $batchRow = $connection->fetchAssociative(
                "SELECT `expectedDelegationCount`, `settledAt` FROM `$batchTable` WHERE `id` = :batchId FOR UPDATE",
                ['batchId' => $batchId]
            );
            if ($batchRow === false || $batchRow['settledAt'] !== null) {
                $connection->commit();
                return false;
            }
            $settledRows = (int)$connection->executeStatement(
                "UPDATE `$batchTable` SET `settledAt` = :now, `foldedAt` = :now WHERE `id` = :batchId AND `settledAt` IS NULL"
                . " AND (SELECT COUNT(*) FROM `$conversationsTable` WHERE `delegationBatchId` = :batchId AND `runState` IN (:terminal)) >= :expected",
                [
                    'now' => (new DateTime())->format('Y-m-d H:i:s'),
                    'batchId' => $batchId,
                    'terminal' => AIConversationRunState::TERMINAL,
                    'expected' => (int)$batchRow['expectedDelegationCount'],
                ],
                ['terminal' => \Doctrine\DBAL\ArrayParameterType::STRING]
            );
            $connection->commit();
            return $settledRows === 1;
        } catch (Throwable $settleError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                $connection->close();
            }
            throw $settleError;
        }
    }

    /** Mark a settled batch as FOLDED — the parent has consumed its verdicts into a turn. Raw UPDATE by id. */
    public function markChildResultsReadByParent(int $batchId): void
    {
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            "UPDATE `$batchTable` SET `foldedAt` = :now WHERE `id` = :batchId AND `foldedAt` IS NULL",
            ['now' => (new DateTime())->format('Y-m-d H:i:s'), 'batchId' => $batchId]
        );
    }

    /**
     * The batch's parent and resume condition, read raw (rights-free, no entity registry) on the wake-winning path —
     * its own questions then decide cancellation (non-ALL cancels losers), the BACKGROUND stop and the parent to wake
     * (formerly an `array{parentConversationId, isAllCondition,
     * isBackgroundCondition}`). Only `id`, `parentConversationId` and `delegateWakeCondition` are filled. Null when the
     * batch is gone.
     */
    public function findBatchRowById(int $batchId): ?AIConversationDelegationBatch
    {
        $batchTable = DBAIConversationDelegationBatchModel::getTableName();
        $row = EntityManagerFactory::getInstance()->getConnection()->fetchAssociative(
            "SELECT `parentConversationId`, `delegateWakeCondition` FROM `$batchTable` WHERE `id` = :batchId",
            ['batchId' => $batchId]
        );
        if ($row === false) {
            return null;
        }
        $batch = AIConversationDelegationBatch::newInstance();
        $batch->id = $batchId;
        $batch->parentConversationId = $row['parentConversationId'] !== null ? (int)$row['parentConversationId'] : null;
        $batch->delegateWakeCondition = (string)$row['delegateWakeCondition'];
        return $batch;
    }
}
