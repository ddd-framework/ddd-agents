<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationResumeCondition;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\AgentSubAgentLiveActivities;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\AgentSubAgentLiveActivity;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\AgentSubAgentLiveStep;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonValueKind;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\Exception;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use Throwable;

/**
 * Who drives a conversation's run, written and read on the `runState` / `runToken` / `runHeartbeatAt` columns of the
 * conversation row: the run claim and its
 * release, the guarded run-state transitions (close, the retry re-arm of a root, the re-arm of a finished delegation
 * child), the liveness heartbeat, the per-process registry of the run tokens this process holds, the admission of
 * queued delegation children into the tree's capacity, and the reads of whether a delegation child's run has ended.
 *
 * Every write is one guarded UPDATE whose affected-rows count names the single winner; none holds a transaction
 * across a model call. The conversation rows themselves (finders, column persists, working-state slices, the reaper
 * sweeps) stay in {@see AIConversationsService}; the owner-consent records are {@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService}.
 *
 * Every statement runs on MariaDB and on PostgreSQL: identifiers and result aliases stand in backticks (the framework
 * turns them into the quoting of the engine), JSON reads and writes, the named lock and the date arithmetic come from
 * the SQL dialect of the connection ({@see EntityManagerFactory::getDialect()}), and every moment is a parameter from
 * the PHP clock. A count of affected rows names the winner only where the WHERE clause excludes the row that already
 * has the written values: MariaDB counts changed rows, PostgreSQL matched ones.
 * Obtained via `DDDService::instance()->getService(AIConversationRunClaimsService::class)`.
 */
class AIConversationRunClaimsService extends Service
{
    use AITraceLogTrait;

    // ── Async run-claim primitives. All are run-token-fenced atomic UPDATEs — never a DB
    //    transaction held across a model call. The claim is the exactly-once gate against the four duplicate-resume
    //    sources (concurrent sibling delegations, RabbitMQ redelivery, the reaper, recover-on-interaction). ──

    /** A turn whose heartbeat is older than this is treated as crashed/hung and is reclaimable (reaper + claim staleness clause). */
    public const int RUN_HUNG_AFTER_SECONDS = 300;

    /** Mint a per-run fencing token (written by {@see claimRun}, re-checked before every append + on release). */
    public function mintRunToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The atomic run-claim: flip IDLE/stale → RUNNING with a fresh runToken in ONE conditional UPDATE. Returns
     * true for the SINGLE winner (affectedRows=1); all concurrent losers get 0 → clean no-op. The
     * `OR runHeartbeatAt < :hungBefore` clause is what lets the reaper steal a CRASHED RUNNING row (plain
     * `runState<>'RUNNING'` never could). Committed immediately — NOT held across the turn (the run-token guards it).
     */
    public function claimRun(AIConversation $conversation, string $runToken, int $hungAfterSeconds = self::RUN_HUNG_AFTER_SECONDS): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $now = new DateTime();
        $hungBefore = (new DateTime())->modify('-' . $hungAfterSeconds . ' seconds');
        $environmentWriteSql = EntityManagerFactory::getDialect()->jsonUpdate('`agentWorkingState`')
            ->set(JsonPath::fromKeys('environment'), ':environment', JsonValueKind::TEXT)
            ->toSql();
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            // agentTurnOwedAt = NULL: a WON claim means a worker drives NOW — the handover marker's
            // single clearing site; liveness responsibility moves to the heartbeat/reaper machinery.
            . ' SET `runState` = :running, `runToken` = :runToken, `runHeartbeatAt` = :now, `agentTurnOwedAt` = NULL,'
            // The environment that drives this turn, as a JSON slice of the working state — the reaper of another
            // environment (production shares the database with every dev workspace) leaves this conversation alone.
            . ' `agentWorkingState` = ' . $environmentWriteSql
            . ' WHERE `id` = :id AND (`runState` <> :running OR `runHeartbeatAt` IS NULL OR `runHeartbeatAt` < :hungBefore)'
            // a DELEGATION child that already finished is never re-claimed — a duplicate or late
            // resume of a COMPLETED / FAILED / CANCELLED child used to win the claim, find no work and release the
            // child IDLE, where every "is anyone still working?" read counted it as executable forever. Roots keep
            // the plain predicate (a FAILED root is retried through rearmRunForRetry, which re-arms it first);
            // an explicit continuation of a finished child re-arms it through rearmTerminalDelegationChild() BEFORE
            // its dispatch, so the claim sees a non-terminal row. Atomic with the claim: a completion landing between
            // the handler's load and this statement is covered by the same predicate.
            // Extended: the SAME exclusion for ROOTS — a delegation-result resume queued for a parent
            // that closed before delivery must not re-claim and re-drive it; a FAILED root is retried through
            // rearmRunForRetry(), which re-arms it to IDLE first.
            . ' AND `runState` NOT IN (:terminalStates)'
            // a QUEUED delegation child waits for its admission — a direct resume (an owner input, a
            // continuation) never bypasses the capacity cap; its input stays persisted and the admitted turn reads it
            . ' AND `runState` <> :queued',
            [
                'running' => AIConversationRunState::RUNNING,
                'queued' => AIConversationRunState::QUEUED,
                'runToken' => $runToken,
                'now' => $now->format('Y-m-d H:i:s'),
                'id' => $conversation->id,
                'hungBefore' => $hungBefore->format('Y-m-d H:i:s'),
                'environment' => AIConversationsService::currentEnvironment(),
                'terminalStates' => AIConversationRunState::TERMINAL,
            ],
            ['terminalStates' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $won = $affectedRows === 1;
        if ($won) {
            self::$runTokensClaimedInThisProcess[(int)$conversation->id] = $runToken;
            $conversation->runState = AIConversationRunState::RUNNING;
            $conversation->runToken = $runToken;
            $conversation->runHeartbeatAt = $now;
            $conversation->agentTurnOwedAt = null;
        }
        return $won;
    }

    /**
     * @var string[] conversation id → the run token THIS process won with {@see claimRun()} and has not
     * released yet. A conversation another process drives carries that process's token on its loaded row — a write
     * fenced on it would act in the other worker's name — so the fenced slice writers take the token from here.
     *
     * A process static on purpose — the documented exception to the rule against process-wide state: it
     * records a fact of the PROCESS (which claims it holds), it fences writes the loop never sees (tools, a purpose
     * of the application, a tool subprocess that adopted its parent's token), and the worker job reset clears it
     * ({@see forgetRunTokensClaimedInThisProcess()}). It is the ONLY authority for the token: `AgentRun` reads it
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRun::findRunTokenClaimedInThisProcess()}) and
     * never holds a copy. Passing the token as a parameter instead is a possible later change.
     */
    protected static array $runTokensClaimedInThisProcess = [];

    /**
     * The run token this process holds for the conversation, or null when this process does not drive it (a CLI or
     * eval run without a claim, the supervisor writing onto the conversation it supervises): the token a fenced write
     * of a worker-owned slice passes.
     */
    public function getRunTokenClaimedInThisProcess(AIConversation $conversation): ?string
    {
        return isset($conversation->id) ? (self::$runTokensClaimedInThisProcess[(int)$conversation->id] ?? null) : null;
    }

    /**
     * This process no longer drives the conversation — its claim was lost (a steal, a terminal write by another
     * process) or the turn ended without its own release: its later writes onto the conversation are an outsider's.
     */
    public function forgetRunTokenClaimedInThisProcess(AIConversation $conversation): void
    {
        if (isset($conversation->id)) {
            unset(self::$runTokensClaimedInThisProcess[(int)$conversation->id]);
        }
    }

    /**
     * A tool subprocess works under the claim of the turn worker that started it: it takes that
     * worker's token as its own, so its writes into the conversation are fenced on the PARENT's claim — once the
     * parent lost it, the subprocess appends nothing into the new owner's turn.
     */
    public function adoptRunTokenOfParentProcess(AIConversation $conversation, string $parentRunToken): void
    {
        if (isset($conversation->id) && $parentRunToken !== '') {
            self::$runTokensClaimedInThisProcess[(int)$conversation->id] = $parentRunToken;
        }
    }

    /** A new worker job starts without the previous job's claims (called by the message handlers' job reset). */
    public static function forgetRunTokensClaimedInThisProcess(): void
    {
        self::$runTokensClaimedInThisProcess = [];
    }

    /** The conversation's current runState by a raw read (no registry, no rights) — null when the row is gone. */
    public function findRunStateById(int $conversationId): ?string
    {
        $runState = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `runState` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` = :id',
            ['id' => $conversationId]
        );
        return is_string($runState) ? $runState : null;
    }

    /** Still hold the claim? (run-token-guarded read, used before every append) */
    public function holdsRunClaim(AIConversation $conversation, string $runToken): bool
    {
        if (!isset($conversation->id) || $runToken === '') {
            return false;
        }
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationModel::getTableName() . '`'
            . ' WHERE `id` = :id AND `runState` = :running AND `runToken` = :runToken',
            ['id' => $conversation->id, 'running' => AIConversationRunState::RUNNING, 'runToken' => $runToken]
        ) === 1;
    }

    /**
     * Whether ANY worker holds a live run claim on the conversation right now: RUNNING with a heartbeat younger than
     * {@see RUN_HUNG_AFTER_SECONDS} — exactly the rows {@see claimRun()} cannot take, so a resume dispatched now would
     * find the claim taken and do nothing. One raw read (no registry, no rights). The injection of a signal a running
     * turn takes over reads it to decide between "the running turn reads it at its next model call" and "resume".
     */
    public function hasLiveRunClaim(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $hungBefore = (new DateTime())->modify('-' . self::RUN_HUNG_AFTER_SECONDS . ' seconds');
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationModel::getTableName() . '`'
            . ' WHERE `id` = :id AND `runState` = :running AND `runHeartbeatAt` IS NOT NULL AND `runHeartbeatAt` >= :hungBefore',
            ['id' => $conversation->id, 'running' => AIConversationRunState::RUNNING, 'hungBefore' => $hungBefore->format('Y-m-d H:i:s')]
        ) === 1;
    }

    /**
     * Touch the liveness heartbeat — ONLY while we still hold the claim (runToken match). Called around every
     * blocking external call (model / MCP tool / Argus) so a long, message-less call doesn't read as hung. Returns
     * false if the claim was lost (another worker took over) — the caller should then stop.
     */
    public function heartbeat(AIConversation $conversation, string $runToken): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $now = new DateTime();
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `runHeartbeatAt` = :now WHERE `id` = :id AND `runToken` = :runToken AND `runState` = :running',
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'id' => $conversation->id,
                'runToken' => $runToken,
                'running' => AIConversationRunState::RUNNING,
            ]
        );
        // Whether we STILL hold the claim is a row-MATCH question (runState=RUNNING + our token), NOT a row-CHANGE one.
        // On MariaDB the UPDATE's affected-rows is 0 when the heartbeat value is UNCHANGED — and the very first step-boundary
        // heartbeat fires in the SAME second as claimRun (which just wrote that second-resolution timestamp), so an
        // affected-rows check would FALSELY read "claim lost" and abort the turn on step 0 (no append). Decide by match.
        $stillHoldsClaim = $this->holdsRunClaim($conversation, $runToken);
        if ($stillHoldsClaim) {
            $conversation->runHeartbeatAt = $now;
        }
        return $stillHoldsClaim;
    }

    /**
     * Keeps a RUNNING turn's crash clock alive from INSIDE a long tool call (a tool that polls an external job for
     * minutes has no step boundary, and {@see heartbeat()} is a step-boundary write): bumps `runHeartbeatAt` only while
     * the row is RUNNING, so it never revives a released or terminal run — the reaper's hung threshold
     * ({@see RUN_HUNG_AFTER_SECONDS}) then measures real silence, not a busy worker. Best-effort: a failed bump is
     * logged by the caller's tool error path, never thrown into the tool call.
     */
    public function touchRunningHeartbeat(int $conversationId): void
    {
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runHeartbeatAt` = :now WHERE `id` = :id AND `runState` = :running',
            ['now' => (new DateTime())->format('Y-m-d H:i:s'), 'id' => $conversationId, 'running' => AIConversationRunState::RUNNING]
        );
    }

    /**
     * Stamp runHeartbeatAt=now WITHOUT claiming (recover-on-read). recoverIfStuck dispatches an
     * ASYNC resume, so the real heartbeat refresh (via the worker's claimRun) lags queue latency — without bumping
     * here, rapid inspector refetches would each re-pass the stale-check and dispatch a BURST of duplicate resumes
     * before the first reclaims. Stamping now makes the next stale-check (FOLD_OWED_STALE_AFTER_SECONDS) fail
     * immediately → one recovery nudge per window, not a storm. Unconditional set (caller already gated on AWAITING +
     * stale), so it never extends a live RUNNING worker's crash clock.
     */
    public function markRecoveryDispatched(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $now = new DateTime();
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runHeartbeatAt` = :now WHERE `id` = :id',
            ['now' => $now->format('Y-m-d H:i:s'), 'id' => $conversation->id]
        );
        $conversation->runHeartbeatAt = $now;
    }

    /**
     * Release the claim to $newRunState (IDLE / AWAITING_DELEGATES / FAILED), clearing the runToken unless staying
     * RUNNING — guarded by the run-token so a stale worker that lost the claim cannot release a live one. Returns
     * whether we still held it.
     *
     * $agentTurnOwedAt: a release that leaves an INBOUND unanswered — the pre-drive throw —
     * re-stamps the handover marker the claim cleared, in the SAME row write as the state + attempt counter (no
     * stamp-after-release gap), so the owed-turn recovery pass owns the retry. A PHP-clock stamp, never SQL NOW():
     * every reader of the marker compares it against PHP timestamps and the DB session clock runs in another timezone.
     */
    public function releaseRun(
        AIConversation $conversation,
        string $runToken,
        string $newRunState,
        ?int $resumeAttempts = null,
        ?DateTime $agentTurnOwedAt = null,
    ): bool {
        if (!isset($conversation->id)) {
            return false;
        }
        $clearToken = $newRunState !== AIConversationRunState::RUNNING;
        $parameters = ['state' => $newRunState, 'id' => $conversation->id, 'runToken' => $runToken];
        $setClause = '`runState` = :state' . ($clearToken ? ', `runToken` = NULL' : '');
        if ($resumeAttempts !== null) {
            $setClause .= ', `resumeAttempts` = :resumeAttempts';
            $parameters['resumeAttempts'] = $resumeAttempts;
        }
        if ($agentTurnOwedAt !== null) {
            $setClause .= ', `agentTurnOwedAt` = :agentTurnOwedAt';
            $parameters['agentTurnOwedAt'] = $agentTurnOwedAt->format('Y-m-d H:i:s');
        }
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET ' . $setClause . ' WHERE `id` = :id AND `runToken` = :runToken',
            $parameters
        );
        if ($affectedRows === 1) {
            $conversation->runState = $newRunState;
            if ($clearToken) {
                $conversation->runToken = null;
                unset(self::$runTokensClaimedInThisProcess[(int)$conversation->id]);
            }
            if ($resumeAttempts !== null) {
                $conversation->resumeAttempts = $resumeAttempts;
            }
            if ($agentTurnOwedAt !== null) {
                $conversation->agentTurnOwedAt = $agentTurnOwedAt;
            }
            return true;
        }
        // The release lost the race (the claim was stolen or ended by another process): this process does not drive
        // the conversation any more either way — a later outsider write of the same job must not be refused.
        if ($clearToken && ($this->getRunTokenClaimedInThisProcess($conversation) === $runToken)) {
            unset(self::$runTokensClaimedInThisProcess[(int)$conversation->id]);
        }
        return false;
    }

    /**
     * Atomically move a conversation's `runState` from one value to another (delegation scheduler) — `affectedRows=1`
     * iff WE won the transition, so two schedulers promoting the same QUEUED child can't both dispatch it. Mirrors the
     * conditional-UPDATE discipline of {@see claimRun}.
     */
    public function transitionRunState(AIConversation $conversation, string $fromRunState, string $toRunState): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $won = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runState` = :to WHERE `id` = :id AND `runState` = :from',
            ['to' => $toRunState, 'id' => $conversation->id, 'from' => $fromRunState]
        ) === 1;
        if ($won) {
            $conversation->runState = $toRunState;
        }
        return $won;
    }

    /**
     * CLOSE the conversation: flip `runState` IDLE → COMPLETED with a guarded
     * UPDATE (`WHERE runState = 'IDLE'`), so an in-flight run-claim is never clobbered — a conversation that
     * is RUNNING again simply does not close (the next settled turn re-evaluates). A closed conversation
     * accepts no further customer messages ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::isClosed()});
     * the purposed-singleton query then treats the purpose as finished (a new start spawns a fresh conversation).
     */
    public function closeConversation(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `runState` = :completedState, `runToken` = NULL WHERE `id` = :id AND `runState` = :idleState',
            [
                'completedState' => AIConversationRunState::COMPLETED,
                'idleState' => AIConversationRunState::IDLE,
                'id' => $conversation->id,
            ]
        );
        if ($affectedRows === 1) {
            $conversation->runState = AIConversationRunState::COMPLETED;
            $conversation->runToken = null;
            return true;
        }
        return false;
    }

    /**
     * Whether the conversation's latest turn is still underway although its row reads IDLE: ONE
     * predicate for every reader that must tell "the agent is done" from "the agent rests between two steps of the same
     * turn" — the A2A task projection (WORKING while it holds), the lost-kickoff revival (fires only without it) and
     * {@see cancelLiveRun()} (an IDLE row is cancellable while it holds). Three reads, each one indexed statement:
     *  - the owed-turn marker `agentTurnOwedAt` (a failed turn under the retry cap, a yield→claim handover) — read from
     *    the row, not from the possibly stale entity;
     *  - an unfinished sub-agent run whose end resumes the conversation ({@see hasUnfinishedSubAgentRunThatResumesParent()});
     *  - a run-end notification still waiting for the conversation's turn — the window after the last sub-agent ended
     *    and before the resume claims the run.
     * Meaningful for an IDLE row only; the caller asks it only then.
     *
     * @throws Exception
     */
    public function isTurnStillUnderwayWhileIdle(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $agentTurnOwedAt = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `agentTurnOwedAt` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` = :id',
            ['id' => (int)$conversation->id]
        );
        if ($agentTurnOwedAt !== false && $agentTurnOwedAt !== null) {
            return true;
        }
        if ($this->hasUnfinishedSubAgentRunThatResumesParent($conversation)) {
            return true;
        }
        return $this->messagesService()->countSignalsWaitingForRunningTurnOfSource($conversation, AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED) > 0;
    }

    /**
     * Whether the turn initiated inside the message-index window `[$fromMessageIndex, $beforeMessageIndex)` ended FAILED:
     * the release records one turn-end event per run (attempt), keyed by the turn's initiator index
     * ({@see AIConversationTurnEvent::OUTCOME_FAILED} is written only when the run ends FAILED over the retry cap). The
     * NEWEST turn-end event of the window decides — a later retry that answered wins over an earlier failed run. An A2A
     * task's window runs from its own message up to, excluding, the next owner text, so a task whose run ended FAILED
     * keeps FAILED after a follow-up re-armed the conversation. Bounded by the turn-event retention (older events are
     * purged, the reader then falls back to its answer-based mapping). `$beforeMessageIndex` null = open end.
     *
     * @throws Exception
     */
    public function hasTurnEndedFailedBetweenMessageIndexes(AIConversation $conversation, int $fromMessageIndex, ?int $beforeMessageIndex): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $parameters = [
            'conversationId' => (int)$conversation->id,
            'turnEndKind' => AIConversationTurnEvent::KIND_TURN_END,
            'fromMessageIndex' => $fromMessageIndex,
        ];
        $beforeClause = '';
        if ($beforeMessageIndex !== null) {
            $beforeClause = ' AND `initiatorMessageIndex` < :beforeMessageIndex';
            $parameters['beforeMessageIndex'] = $beforeMessageIndex;
        }
        $newestTurnEndOutcome = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT ' . EntityManagerFactory::getDialect()->jsonFieldText('`payload`', 'outcome')
            . ' FROM `' . DBAIConversationTurnEventModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `kind` = :turnEndKind AND `initiatorMessageIndex` >= :fromMessageIndex' . $beforeClause
            . ' ORDER BY `id` DESC LIMIT 1',
            $parameters
        );
        return $newestTurnEndOutcome === AIConversationTurnEvent::OUTCOME_FAILED;
    }

    /**
     * Cancel the conversation's live run — ONE guarded operation, the only run-state write of the A2A
     * cancel. Inside one transaction that holds the conversation row (`SELECT … FOR UPDATE`, so a claim, a release or a
     * resume of this row waits for the decision):
     *  - QUEUED / RUNNING / AWAITING_DELEGATES are cancelled unconditionally;
     *  - IDLE is cancelled only while the latest turn is not over — {@see isTurnStillUnderwayWhileIdle()} holds (a
     *    delegating task with an interim text, an owed retried turn) or the newest owner text has no assistant text after
     *    it (a lost kickoff) — re-checked here under the lock, so a completion that landed after the caller's read is
     *    never overwritten;
     *  - a terminal row (COMPLETED / FAILED / CANCELLED) and an answered IDLE row are left as they are: no write, no publish.
     * The write is `runState = CANCELLED, runToken = NULL, agentTurnOwedAt = NULL` (the {@see closeConversation()}
     * pattern): the worker that still runs the turn loses its claim at its next heartbeat, and its token-fenced release
     * ({@see releaseRun()}) becomes a no-op — a cancel landing after the last heartbeat and before the release is never
     * overwritten by IDLE. The unfinished sub-agent runs of the tree are cancelled in the same transaction (the
     * whole tree stops). CANCELLED is a closed state: no claim takes the row again ({@see claimRun()}), a run-end
     * notification never resumes it, a new owner message is refused. After the commit ONE change signal with the status
     * {@see AIConversationRealtimeSignalService::STATUS_CANCELLED} goes out (Firebase, and the A2A webhook projects
     * CANCELED). Returns whether THIS call cancelled the run.
     *
     * @throws Exception
     * @throws Throwable
     */
    public function cancelLiveRun(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationTableName = DBAIConversationModel::getTableName();
        $cancelledSubAgentChildIds = [];
        // READ COMMITTED like the sibling run-end transactions: every read after the row lock sees the latest commits
        $this->transactionsService()->beginReadCommittedTransaction($connection);
        try {
            $lockedRunState = $connection->fetchOne(
                "SELECT `runState` FROM `{$conversationTableName}` WHERE `id` = :id FOR UPDATE",
                ['id' => (int)$conversation->id]
            );
            if (!$this->isRunCancellableUnderRowLock($conversation, is_string($lockedRunState) ? $lockedRunState : null)) {
                $connection->commit();
                return false;
            }
            $connection->executeStatement(
                "UPDATE `{$conversationTableName}` SET `runState` = :cancelled, `runToken` = NULL, `agentTurnOwedAt` = NULL WHERE `id` = :id",
                ['cancelled' => AIConversationRunState::CANCELLED, 'id' => (int)$conversation->id]
            );
            $cancelledSubAgentChildIds = $this->cancelUnfinishedSubAgentRunsOfParent($conversation);
            $connection->commit();
        } catch (Throwable $cancelError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            } else {
                $connection->close();
            }
            throw $cancelError;
        }
        $conversation->runState = AIConversationRunState::CANCELLED;
        $conversation->runToken = null;
        $conversation->agentTurnOwedAt = null;
        $this->forgetRunTokenClaimedInThisProcess($conversation);
        $this->aiTrace('run.cancelled', ['conversationId' => $conversation->id, 'cancelledSubAgentChildIds' => $cancelledSubAgentChildIds]);
        $this->realtimeSignalService()->publishChangeSignal($conversation, AIConversationRealtimeSignalService::STATUS_CANCELLED);
        return true;
    }

    /**
     * The guard of {@see cancelLiveRun()}, asked while the conversation row is locked: a live run state is cancellable,
     * an IDLE row only while its latest turn is not over, a terminal or unknown row never.
     *
     * @throws Exception
     */
    protected function isRunCancellableUnderRowLock(AIConversation $conversation, ?string $lockedRunState): bool
    {
        if (in_array($lockedRunState, AIConversationRunState::NON_TERMINAL, true)) {
            return true;
        }
        if ($lockedRunState !== AIConversationRunState::IDLE) {
            return false;
        }
        return $this->isTurnStillUnderwayWhileIdle($conversation) || !$this->hasAssistantTextAfterNewestOwnerText($conversation);
    }

    /**
     * Whether an assistant text with content follows the conversation's newest owner text — the raw form of "the latest
     * turn has an answer" (the A2A projection reads the same rows: an ASSISTANT-role TEXT row with a non-blank text).
     *
     * @throws Exception
     */
    protected function hasAssistantTextAfterNewestOwnerText(AIConversation $conversation): bool
    {
        $messageTableName = DBAIConversationMessageModel::getTableName();
        return EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            "SELECT `answer`.`id` FROM `{$messageTableName}` `answer`"
            . ' WHERE `answer`.`aiConversationId` = :conversationId AND `answer`.`role` = :assistantRole AND `answer`.`messageType` = :textType'
            . " AND `answer`.`truncatedAt` IS NULL AND TRIM(COALESCE(`answer`.`message`, '')) <> ''"
            . " AND `answer`.`indexInConversation` > (SELECT COALESCE(MAX(`ownerText`.`indexInConversation`), -1) FROM `{$messageTableName}` `ownerText`"
            . ' WHERE `ownerText`.`aiConversationId` = :conversationId AND `ownerText`.`role` = :userRole AND `ownerText`.`messageType` = :textType'
            . ' AND `ownerText`.`authorType` = :userAuthor AND `ownerText`.`truncatedAt` IS NULL)'
            . ' LIMIT 1',
            [
                'conversationId' => (int)$conversation->id,
                'assistantRole' => AIConversationMessage::ROLE_ASSISTANT,
                'userRole' => AIConversationMessage::ROLE_USER,
                'textType' => AIConversationMessage::MESSAGE_TEXT,
                'userAuthor' => AIConversationMessage::AUTHOR_USER,
            ]
        ) !== false;
    }

    /**
     * Cancel every unfinished sub-agent run of the conversation's tree (the whole tree stops), inside the caller's
     * transaction — the same three writes as the supersede of a not-started run
     * ({@see AIConversationDelegationBatchesService::cancelQueuedSubAgentRunsOfDeadlines()}), per child:
     *  - its `delegate` row is locked first (as every end of the run locks it, so the child's own end and the reaper's
     *    timeout serialize on it), the record's current run ends CANCELLED `cancelledByOwner` and is stamped reported —
     *    the parent is closed, so no notification is owed and the notification recovery never sends one;
     *  - its deadline row is stamped ended, so the reaper never times the run out into the closed parent;
     *  - the child row moves to CANCELLED with its token and admission stamp cleared, guarded on a run state that has not
     *    ended, so a child that ended meanwhile keeps its own end. A running child loses its claim at its next heartbeat.
     * The children are the ones {@see findUnfinishedSubAgentChildIdsOfParent()} names (the tree of a root — every
     * descendant carries the root's id). A legacy row (no sub-agent record on its `delegate` row) gets the child write
     * only. Returns the ids it cancelled.
     *
     * @return int[]
     * @throws Exception
     */
    protected function cancelUnfinishedSubAgentRunsOfParent(AIConversation $parentConversation): array
    {
        $unfinishedChildIds = $this->findUnfinishedSubAgentChildIdsOfParent($parentConversation);
        if ($unfinishedChildIds === []) {
            return [];
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $childRows = $connection->fetchAllAssociative(
            'SELECT `id`, `delegatedFromMessageId`, `delegationBatchId` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` IN (:childIds) ORDER BY `id` ASC',
            ['childIds' => $unfinishedChildIds],
            ['childIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $cancelledAt = new DateTime();
        foreach ($childRows as $childRow) {
            if ($childRow['delegatedFromMessageId'] !== null) {
                $this->endSubAgentRunCancelledWithItsParent((int)$childRow['delegatedFromMessageId'], $childRow['delegationBatchId'] !== null ? (int)$childRow['delegationBatchId'] : null, $cancelledAt);
            }
        }
        $connection->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `runState` = :cancelled, `runToken` = NULL, `agentTurnOwedAt` = NULL'
            . ' WHERE `id` IN (:childIds) AND `rootConversationId` = :parentConversationId AND `runState` IN (:unfinishedRunStates)',
            [
                'cancelled' => AIConversationRunState::CANCELLED,
                'childIds' => $unfinishedChildIds,
                'parentConversationId' => (int)$parentConversation->id,
                'unfinishedRunStates' => self::SUB_AGENT_UNFINISHED_RUN_STATES,
            ],
            [
                'childIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
                'unfinishedRunStates' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ]
        );
        return $unfinishedChildIds;
    }

    /**
     * The run end of one sub-agent cancelled with its parent, inside the caller's transaction: the `delegate` row locked,
     * the current run ended CANCELLED `cancelledByOwner` and stamped reported, the run's deadline row ended. A `delegate`
     * row without a sub-agent record (legacy) or whose run already ended writes nothing to the record; the deadline row
     * is ended only together with a written run end.
     *
     * @throws Exception
     */
    protected function endSubAgentRunCancelledWithItsParent(int $delegateCallMessageId, ?int $deadlineId, DateTime $cancelledAt): void
    {
        $delegateCallRow = $this->messagesService()->lockStoredDelegateCallRow($delegateCallMessageId);
        $subAgentRecord = isset($delegateCallRow->delegationResults) && !$delegateCallRow->delegationResults->isLegacyMap()
            ? ($delegateCallRow->delegationResults->subAgent ?? null)
            : null;
        if ($subAgentRecord === null) {
            return;
        }
        $runNumber = $subAgentRecord->currentRunNumber;
        $runEndWritten = $this->messagesService()->writeSubAgentRunEnded($delegateCallMessageId, DelegationSubAgentRun::ended(
            $runNumber,
            DelegationSubAgentRun::STATUS_CANCELLED,
            $cancelledAt,
            null,
            DelegationSubAgentRun::FAILURE_REASON_CANCELLED_BY_OWNER,
            'the task that started it was cancelled',
            null
        ));
        if (!$runEndWritten) {
            return;
        }
        $this->messagesService()->stampSubAgentRunReportedToParent($delegateCallMessageId, $runNumber, $cancelledAt);
        if ($deadlineId !== null) {
            $this->delegationBatchesService()->markSubAgentRunDeadlineEnded($deadlineId, $cancelledAt);
        }
    }

    protected function delegationBatchesService(): AIConversationDelegationBatchesService
    {
        /** @var AIConversationDelegationBatchesService $aIConversationDelegationBatchesService */
        $aIConversationDelegationBatchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
        return $aIConversationDelegationBatchesService;
    }

    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    protected function transactionsService(): AIConversationTransactionsService
    {
        /** @var AIConversationTransactionsService $aIConversationTransactionsService */
        $aIConversationTransactionsService = DDDService::instance()->getService(AIConversationTransactionsService::class);
        return $aIConversationTransactionsService;
    }

    protected function realtimeSignalService(): AIConversationRealtimeSignalService
    {
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        return $aIConversationRealtimeSignalService;
    }

    /**
     * Re-arm a ROOT run that owes an answer for ONE customer-requested retry (the FE "Retry"
     * action): a FAILED run (the retry cap exhausted, a crashed worker the reaper expired) flips to IDLE with the
     * attempt counter reset; an IDLE run whose turn errored under the cap (the loop released it for the automatic
     * retry the owed-turn pass drives only after its quiet window — the customer saw the FAILED signal and pressed
     * the button meanwhile) is re-armed WITHOUT touching the counter, so a structurally broken turn still escalates
     * to FAILED after the cap instead of being reset by every click. Both clear the run-token and STAMP the owed
     * marker (PHP clock) in ONE guarded UPDATE (`WHERE runState IN ('FAILED', 'IDLE')`), so a conversation a worker
     * meanwhile re-claimed (RUNNING) is never clobbered; a delegation child never matches — its re-arm is
     * {@see rearmTerminalDelegationChild()}. The stamp is the safety net: the caller dispatches ONE resume, and if
     * that dispatch is lost the owed-turn recovery pass still owns the row (a won claim clears the marker, so a
     * delivered dispatch never double-drives). Returns whether THIS call re-armed it; the persisted inbound message
     * is re-driven — the customer is never asked to send it again.
     *
     * @param AIConversation $conversation The root conversation whose FAILED or IDLE run to re-arm
     *
     * @return bool
     * @throws \Doctrine\DBAL\Exception
     */
    public function rearmRunForRetry(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $owedAt = new DateTime();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        // ORDER MATTERS on MariaDB: it evaluates the SET list left to right against the row AS ALREADY UPDATED by the
        // assignments before it — the counter expression must read runState BEFORE the flip to IDLE, or it sees
        // IDLE for every row and never resets (the DB-backed test caught exactly that). PostgreSQL evaluates every
        // assignment against the stored row, so the same order is right there.
        $affectedRows = (int)$connection->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `resumeAttempts` = CASE WHEN `runState` = :failed THEN 0 ELSE `resumeAttempts` END,'
            . ' `runState` = :idle, `runToken` = NULL, `agentTurnOwedAt` = :owedAt'
            . ' WHERE `id` = :id AND `runState` IN (:failed, :idle) AND `delegatedFromMessageId` IS NULL',
            [
                'idle' => AIConversationRunState::IDLE,
                'failed' => AIConversationRunState::FAILED,
                'owedAt' => $owedAt->format('Y-m-d H:i:s'),
                'id' => $conversation->id,
            ]
        );
        // MariaDB reports CHANGED rows, not matched ones: a second click within the same second on an
        // already re-armed IDLE row matches and changes nothing (same state, same token, same second-resolution
        // stamp) — that row is still ours and still owed, never a refusal. Read the row back to tell "not matched"
        // (RUNNING, a child) from "matched, nothing to change"; the mirror below then reflects the ROW, not the
        // possibly stale entity the caller loaded.
        $persistedRunColumns = $connection->fetchAssociative(
            'SELECT `runState`, `resumeAttempts`, `agentTurnOwedAt` FROM `' . DBAIConversationModel::getTableName() . '`'
            . ' WHERE `id` = :id AND `delegatedFromMessageId` IS NULL',
            ['id' => $conversation->id]
        );
        $rowIsRearmed = is_array($persistedRunColumns)
            && $persistedRunColumns['runState'] === AIConversationRunState::IDLE
            && $persistedRunColumns['agentTurnOwedAt'] !== null;
        if ($affectedRows !== 1 && !$rowIsRearmed) {
            return false;
        }
        $conversation->runState = AIConversationRunState::IDLE;
        $conversation->runToken = null;
        $conversation->resumeAttempts = (int)$persistedRunColumns['resumeAttempts'];
        $conversation->agentTurnOwedAt = DateTime::fromString((string)$persistedRunColumns['agentTurnOwedAt']);
        return true;
    }

    /**
     * EXPLICIT continuation of a finished DELEGATION child: admin Continue / Edit and the async
     * CLI re-arm the child BEFORE dispatching, so the terminal-child exclusion in {@see claimRun()} sees a non-terminal
     * row. It is re-QUEUED and then admitted like every child ({@see admitQueuedDelegationChildren()}),
     * so a continuation never bypasses the capacity cap. ONE guarded statement that matches terminal rows only — a
     * live worker's token is never cleared (a RUNNING / QUEUED / AWAITING_DELEGATES row does not match), a root never
     * matches (its retry path is {@see rearmRunForRetry()}). With `$continuationRunDeadline` (the deadline
     * row of the sub-agent's next run) the same statement points the child's `delegationBatchId` at it. Returns whether
     * THIS call re-armed it.
     */
    public function rearmTerminalDelegationChild(AIConversation $conversation, ?AIConversationDelegationBatch $continuationRunDeadline = null): bool
    {
        if (!isset($conversation->id) || !$conversation->isDelegation()) {
            return false;
        }
        $rearmParameters = [
            'queued' => AIConversationRunState::QUEUED,
            'id' => $conversation->id,
            'terminalStates' => AIConversationRunState::TERMINAL,
        ];
        $deadlineAssignment = '';
        if (isset($continuationRunDeadline?->id)) {
            $deadlineAssignment = ', `delegationBatchId` = :continuationRunDeadlineId';
            $rearmParameters['continuationRunDeadlineId'] = (int)$continuationRunDeadline->id;
        }
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `runState` = :queued, `runToken` = NULL, `resumeAttempts` = 0, `agentTurnOwedAt` = NULL' . $deadlineAssignment
            . ' WHERE `id` = :id AND `delegatedFromMessageId` IS NOT NULL AND `runState` IN (:terminalStates)',
            $rearmParameters,
            ['terminalStates' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        if ($affectedRows !== 1) {
            return false;
        }
        if (isset($continuationRunDeadline?->id)) {
            $conversation->delegationBatchId = (int)$continuationRunDeadline->id;
        }
        $conversation->runState = AIConversationRunState::QUEUED;
        $conversation->runToken = null;
        $conversation->resumeAttempts = 0;
        $conversation->agentTurnOwedAt = null;
        return true;
    }

    /**
     * Global cap on concurrently in-flight delegation WORKERS per delegation tree (root). A turn's fan-out
     * beyond this leaves the excess delegates QUEUED; the scheduler promotes them as siblings settle. Bounds parallel
     * LLM spend + worker pressure.
     */
    public const int DELEGATION_CONCURRENCY_CAP = 5;

    /**
     * The delegation SLOTS occupied under a tree: a child that runs (RUNNING) or one admitted and not
     * yet claimed — IDLE with the admission stamp `agentTurnOwedAt` while its batch is unsettled (a child without a
     * batch counts while stamped). A parked (AWAITING_DELEGATES) or QUEUED child holds no slot; its own running
     * children count instead. Legacy IDLE children carry no stamp and never count. A root carries
     * `rootConversationId = NULL`; `id <> :root` keeps an un-repaired legacy root out.
     */
    public function countOccupiedDelegationSlotsUnderRoot(int $rootConversationId): int
    {
        $conversationTableName = DBAIConversationModel::getTableName();
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM `{$conversationTableName}` `child`"
            . ' LEFT JOIN `' . DBAIConversationDelegationBatchModel::getTableName() . '` `batch` ON `batch`.`id` = `child`.`delegationBatchId`'
            . ' WHERE `child`.`rootConversationId` = :root AND `child`.`id` <> :root'
            . ' AND (`child`.`runState` = :running OR (`child`.`runState` = :idle AND `child`.`agentTurnOwedAt` IS NOT NULL AND `batch`.`settledAt` IS NULL))',
            ['root' => $rootConversationId, 'running' => AIConversationRunState::RUNNING, 'idle' => AIConversationRunState::IDLE]
        );
    }

    /**
     * Admit QUEUED delegation children of a tree into the free capacity — the ONE admission path of a
     * fresh spawn, a promotion after a sibling settled and a continuation. Under the tree's named lock
     * (`delegation-capacity-<root>`), the free slots are counted and the oldest queued children moved
     * `QUEUED → IDLE` stamping `agentTurnOwedAt` in the same statement: the stamp IS the reservation (it counts as
     * occupied until the child's claim clears it) and the recovery hook (the owed-turn pass re-dispatches a lost
     * dispatch). Returns the ids admitted now; the caller dispatches them. A lock not obtained admits nothing — the
     * next settle, spawn or recovery pass admits.
     *
     * @return int[]
     * @throws \Doctrine\DBAL\Exception
     */
    public function admitQueuedDelegationChildren(int $rootConversationId): array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $lockName = self::DELEGATION_CAPACITY_LOCK_PREFIX . $rootConversationId;
        // a named lock outside a transaction: MariaDB holds it on this connection, PostgreSQL on the framework's own
        // direct connection for named locks
        $namedLocks = EntityManagerFactory::getDialect()->locks($connection);
        if (!$namedLocks->acquire($lockName, self::DELEGATION_CAPACITY_LOCK_TIMEOUT_SECONDS)) {
            return [];
        }
        try {
            $freeSlots = self::DELEGATION_CONCURRENCY_CAP - $this->countOccupiedDelegationSlotsUnderRoot($rootConversationId);
            $admittedChildIds = [];
            foreach ($this->findQueuedDelegationChildIds($rootConversationId, max(0, $freeSlots)) as $queuedChildId) {
                $admitted = (int)$connection->executeStatement(
                    'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runState` = :idle, `agentTurnOwedAt` = :admittedAt WHERE `id` = :id AND `runState` = :queued',
                    ['idle' => AIConversationRunState::IDLE, 'admittedAt' => (new DateTime())->format('Y-m-d H:i:s'), 'id' => $queuedChildId, 'queued' => AIConversationRunState::QUEUED]
                ) === 1;
                if ($admitted) {
                    $admittedChildIds[] = $queuedChildId;
                    $this->extendDeadlineOfAdmittedChild($queuedChildId);
                }
            }
            return $admittedChildIds;
        } finally {
            try {
                $namedLocks->release($lockName);
            } catch (\Throwable) {
                // the lock ends with the session
            }
        }
    }

    /**
     * The admitted child's deadline row gets a full window from now: a child
     * admitted near the end of its queued-wait extension is IDLE (not yet claimed) — the reaper extends a QUEUED or a
     * live RUNNING member only, so without this it could time the run out before it ever ran. Only pushes the deadline
     * forward (never shortens it), only while the row has not ended. One guarded statement over the deadline row the
     * child names (a single-table UPDATE: an UPDATE over a join has no form both engines share).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function extendDeadlineOfAdmittedChild(int $admittedChildId): void
    {
        $now = (new DateTime())->format(DateTime::SIMPLE);
        $fullWindowFromNowSql = EntityManagerFactory::getDialect()->dateAdd(':now', '`timeoutSeconds`', 'SECOND');
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationDelegationBatchModel::getTableName() . '`'
            . ' SET `deadlineAt` = GREATEST(COALESCE(`deadlineAt`, :now), ' . $fullWindowFromNowSql . ')'
            . ' WHERE `id` = (SELECT `delegationBatchId` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` = :childId)'
            . ' AND `settledAt` IS NULL',
            ['now' => $now, 'childId' => $admittedChildId]
        );
    }

    /** The named lock that serialises the admissions of one delegation tree. */
    public const string DELEGATION_CAPACITY_LOCK_PREFIX = 'delegation-capacity-';

    /** How long an admission waits for its tree's capacity lock. */
    public const int DELEGATION_CAPACITY_LOCK_TIMEOUT_SECONDS = 5;

    /**
     * The ids of QUEUED delegation children under a tree (delegation scheduler), oldest first, capped at $limit — the
     * promotion candidates when a slot frees. FIFO by id so the earliest-queued delegate runs next.
     *
     * @return int[]
     */
    protected function findQueuedDelegationChildIds(int $rootConversationId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $ids = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `id` FROM `' . DBAIConversationModel::getTableName() . '`'
            . ' WHERE `rootConversationId` = :root AND `id` <> :root AND `runState` = :queued ORDER BY `id` ASC LIMIT ' . $limit,
            ['root' => $rootConversationId, 'queued' => AIConversationRunState::QUEUED]
        );
        return array_map('intval', $ids);
    }

    /** The delegate call a delegation child was spawned by, one raw read by id — null for a root or a gone row. */
    public function findDelegatedFromMessageIdById(int $conversationId): ?int
    {
        $delegatedFromMessageId = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `delegatedFromMessageId` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` = :id',
            ['id' => $conversationId]
        );
        return $delegatedFromMessageId !== false && $delegatedFromMessageId !== null ? (int)$delegatedFromMessageId : null;
    }

    /**
     * An awaited conversation becomes the child of a sub-agent: the three anchor columns — the `delegate`
     * row, the run's deadline row, the tree's root — written in ONE guarded statement, only while the conversation has
     * not ended and belongs to no other `delegate` row. The conversation may be in the middle of a turn in another worker:
     * nothing else of it is touched, and that worker adopts the anchor at its turn end
     * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationResultsService::adoptDelegationAnchorJoinedMidTurn()}).
     * Returns whether it was stamped.
     *
     * @throws Exception
     */
    public function stampAwaitedConversationAsSubAgentChild(int $awaitedConversationId, int $delegateCallMessageId, int $deadlineId, int $rootConversationId): bool
    {
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName() . '`'
            . ' SET `delegatedFromMessageId` = :delegateCallMessageId, `delegationBatchId` = :deadlineId, `rootConversationId` = :rootConversationId'
            . ' WHERE `id` = :awaitedConversationId AND `id` <> :rootConversationId AND `delegatedFromMessageId` IS NULL AND `runState` NOT IN (:terminalRunStates)',
            [
                'delegateCallMessageId' => $delegateCallMessageId,
                'deadlineId' => $deadlineId,
                'rootConversationId' => $rootConversationId,
                'awaitedConversationId' => $awaitedConversationId,
                'terminalRunStates' => AIConversationRunState::TERMINAL,
            ],
            ['terminalRunStates' => \Doctrine\DBAL\ArrayParameterType::STRING]
        ) === 1;
    }

    /** @var string[] the run states of a delegation child whose run has not ended */
    public const array SUB_AGENT_UNFINISHED_RUN_STATES = [
        AIConversationRunState::QUEUED,
        AIConversationRunState::IDLE,
        AIConversationRunState::RUNNING,
        AIConversationRunState::AWAITING_DELEGATES,
    ];

    /** How many of a running sub-agent's newest steps {@see findSubAgentLiveActivities()} reads (the drill-down shows them). */
    public const int SUB_AGENT_LIVE_STEPS_LIMIT = 5;

    /**
     * Whether a sub-agent of this conversation has a run that has not ended — one indexed read of the
     * child rows, never of the parent's loaded window. See {@see findUnfinishedSubAgentChildIdsOfParent()} for the rule.
     *
     * @throws Exception
     */
    public function hasUnfinishedSubAgentRunOfParent(AIConversation $parentConversation): bool
    {
        return $this->findUnfinishedSubAgentChildIdsOfParentUpTo($parentConversation, 1) !== [];
    }

    /**
     * Whether a sub-agent of this conversation has an unfinished run whose end RESUMES the conversation — {@see hasUnfinishedSubAgentRunOfParent()} without the runs that notify without resuming (the record's
     * `notifiesParentWithoutResuming`, a legacy BACKGROUND batch member): the short wait at the head of a
     * notification-started turn and the offer of `wait_for_sub_agents` ask it, so neither ever waits for a notification
     * that will not resume the parent. One indexed read of the child rows.
     *
     * @throws Exception
     */
    public function hasUnfinishedSubAgentRunThatResumesParent(AIConversation $parentConversation): bool
    {
        if (!isset($parentConversation->id)) {
            return false;
        }
        $sqlDialect = EntityManagerFactory::getDialect();
        $delegateCallStatusSql = $sqlDialect->jsonFieldText('`delegateCall`.`toolCall`', 'status');
        $notifiesParentWithoutResumingSql = $sqlDialect->jsonText(
            '`delegateCall`.`delegationResults`',
            JsonPath::fromKeys('subAgent', 'notifiesParentWithoutResuming')
        );
        return EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `child`.`id` FROM `' . DBAIConversationModel::getTableName() . '` `child`'
            . ' LEFT JOIN `' . DBAIConversationMessageModel::getTableName() . '` `delegateCall` ON `delegateCall`.`id` = `child`.`delegatedFromMessageId`'
            . ' LEFT JOIN `' . DBAIConversationDelegationBatchModel::getTableName() . '` `deadline` ON `deadline`.`id` = `child`.`delegationBatchId`'
            . ' WHERE `child`.`rootConversationId` = :parentConversationId AND `child`.`id` <> :parentConversationId'
            . ' AND `child`.`delegatedFromMessageId` IS NOT NULL AND `child`.`runState` IN (:unfinishedRunStates)'
            . ' AND NOT (`child`.`runState` = :idle AND `child`.`delegationBatchId` IS NULL'
            . " AND COALESCE({$delegateCallStatusSql}, '') IN (:endedCallStatuses))"
            . " AND COALESCE({$notifiesParentWithoutResumingSql}, 'false') <> 'true'"
            . " AND COALESCE(`deadline`.`delegateWakeCondition`, '') <> :background"
            . ' LIMIT 1',
            [
                'parentConversationId' => (int)$parentConversation->id,
                'unfinishedRunStates' => self::SUB_AGENT_UNFINISHED_RUN_STATES,
                'idle' => AIConversationRunState::IDLE,
                'endedCallStatuses' => [AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_FAILED],
                'background' => AIConversationDelegationResumeCondition::BACKGROUND,
            ],
            [
                'unfinishedRunStates' => \Doctrine\DBAL\ArrayParameterType::STRING,
                'endedCallStatuses' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ]
        ) !== false;
    }

    /**
     * {@see hasUnfinishedSubAgentRunThatResumesParent()} for the agent loop (moved from it):
     * the one read behind the offer of `wait_for_sub_agents` and the short wait at the head of a notification-started
     * turn. Fail-soft (false): without the read the turn answers instead of waiting.
     */
    public function hasUnfinishedSubAgentRunThatResumesParentWithoutThrowing(AIConversation $parentConversation): bool
    {
        if (!isset($parentConversation->id)) {
            return false;
        }
        try {
            return $this->hasUnfinishedSubAgentRunThatResumesParent($parentConversation);
        } catch (Throwable $unfinishedRunReadError) {
            $this->aiTrace('turn.subAgents.unfinishedReadFailed', ['conversationId' => $parentConversation->id, 'error' => $unfinishedRunReadError->getMessage()]);
            return false;
        }
    }

    /**
     * Whether the current turn of the loaded conversation was opened by sub-agent notifications only and a sub-agent
     * whose end resumes the parent still runs — the loop reads it ONCE, after its first step's
     * hand-over, into {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRun::$hasUnfinishedSubAgentRunAtLoopStart};
     * the offer of `wait_for_sub_agents` reads that flag at every step. Fail-soft like the read behind it.
     */
    public function hasUnfinishedSubAgentRunOnANotificationTurn(AIConversation $parentConversation): bool
    {
        return isset($parentConversation->messages)
            && $parentConversation->messages->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly()
            && $this->hasUnfinishedSubAgentRunThatResumesParentWithoutThrowing($parentConversation);
    }

    /**
     * The delegation children of this conversation whose run has not ended, oldest first: children
     * of its tree (`rootConversationId`; one level — the parent IS the root) spawned by a `delegate` row and in a run
     * state that has not ended ({@see SUB_AGENT_UNFINISHED_RUN_STATES}). Legacy rule: a child the
     * sync path ran inline stayed IDLE without a deadline row although its `delegate` call is COMPLETED / FAILED — it has
     * ended. Covers legacy children too: they are child rows as well. Raw, rights-agnostic (worker context).
     *
     * @return int[]
     * @throws Exception
     */
    public function findUnfinishedSubAgentChildIdsOfParent(AIConversation $parentConversation): array
    {
        return $this->findUnfinishedSubAgentChildIdsOfParentUpTo($parentConversation, null);
    }

    /**
     * {@see findUnfinishedSubAgentChildIdsOfParent()} with an optional row limit (null = all).
     *
     * @return int[]
     * @throws Exception
     */
    protected function findUnfinishedSubAgentChildIdsOfParentUpTo(AIConversation $parentConversation, ?int $limit): array
    {
        if (!isset($parentConversation->id)) {
            return [];
        }
        $delegateCallStatusSql = EntityManagerFactory::getDialect()->jsonFieldText('`delegateCall`.`toolCall`', 'status');
        $childIds = EntityManagerFactory::getInstance()->getConnection()->fetchFirstColumn(
            'SELECT `child`.`id` FROM `' . DBAIConversationModel::getTableName() . '` `child`'
            . ' LEFT JOIN `' . DBAIConversationMessageModel::getTableName() . '` `delegateCall` ON `delegateCall`.`id` = `child`.`delegatedFromMessageId`'
            . ' WHERE `child`.`rootConversationId` = :parentConversationId AND `child`.`id` <> :parentConversationId'
            . ' AND `child`.`delegatedFromMessageId` IS NOT NULL AND `child`.`runState` IN (:unfinishedRunStates)'
            . ' AND NOT (`child`.`runState` = :idle AND `child`.`delegationBatchId` IS NULL'
            . " AND COALESCE({$delegateCallStatusSql}, '') IN (:endedCallStatuses))"
            . ' ORDER BY `child`.`id` ASC' . ($limit === null ? '' : ' LIMIT ' . max(1, $limit)),
            [
                'parentConversationId' => (int)$parentConversation->id,
                'unfinishedRunStates' => self::SUB_AGENT_UNFINISHED_RUN_STATES,
                'idle' => AIConversationRunState::IDLE,
                'endedCallStatuses' => [AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_FAILED],
            ],
            [
                'unfinishedRunStates' => \Doctrine\DBAL\ArrayParameterType::STRING,
                'endedCallStatuses' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ]
        );
        return array_map('intval', $childIds);
    }

    /**
     * What the sub-agents with an unfinished run do right now, read live and never stored — two
     * statements over the children of `$delegationSubAgentRecords` whose current run has not ended: their run state and
     * heartbeat; the number of tool-call steps since their current run started (queued) and their newest
     * {@see SUB_AGENT_LIVE_STEPS_LIMIT} steps. A record without a child or with an ended run is left out.
     *
     * @throws Exception
     */
    public function findSubAgentLiveActivities(DelegationSubAgentRecords $delegationSubAgentRecords): AgentSubAgentLiveActivities
    {
        $agentSubAgentLiveActivities = new AgentSubAgentLiveActivities();
        $currentRunStartByChildId = [];
        foreach ($delegationSubAgentRecords->getUnfinished()->getElements() as $unfinishedRecord) {
            if ($unfinishedRecord->childConversationId === null) {
                continue;
            }
            $currentRun = $unfinishedRecord->getCurrentRun();
            $currentRunStartByChildId[$unfinishedRecord->childConversationId] = $currentRun?->queuedAt ?? $currentRun?->startedAt;
        }
        if ($currentRunStartByChildId === []) {
            return $agentSubAgentLiveActivities;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $childRows = $connection->fetchAllAssociative(
            'SELECT `id`, `runState`, `runHeartbeatAt` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` IN (:childIds) ORDER BY `id` ASC',
            ['childIds' => array_keys($currentRunStartByChildId)],
            ['childIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $stepConditions = [];
        $stepParameters = [
            'toolCallType' => AIConversationMessage::MESSAGE_TOOL_CALL,
            'stepsLimit' => self::SUB_AGENT_LIVE_STEPS_LIMIT,
        ];
        $childNumber = 0;
        foreach ($currentRunStartByChildId as $childId => $currentRunStart) {
            $stepConditions[] = '(`m`.`aiConversationId` = :child' . $childNumber
                . ($currentRunStart === null ? '' : ' AND `m`.`created` >= :since' . $childNumber) . ')';
            $stepParameters['child' . $childNumber] = $childId;
            if ($currentRunStart !== null) {
                $stepParameters['since' . $childNumber] = $currentRunStart->format(DateTime::SIMPLE);
            }
            $childNumber++;
        }
        $toolCallStatusSql = EntityManagerFactory::getDialect()->jsonFieldText('`m`.`toolCall`', 'status');
        $stepRows = $connection->fetchAllAssociative(
            'SELECT `aiConversationId`, `toolName`, `toolCallStatus`, `created`, `stepCount` FROM ('
            . "SELECT `m`.`aiConversationId`, `m`.`virtualToolName` AS `toolName`, {$toolCallStatusSql} AS `toolCallStatus`, `m`.`created`,"
            . ' ROW_NUMBER() OVER (PARTITION BY `m`.`aiConversationId` ORDER BY `m`.`id` DESC) AS `stepRank`,'
            . ' COUNT(*) OVER (PARTITION BY `m`.`aiConversationId`) AS `stepCount`'
            . ' FROM `' . DBAIConversationMessageModel::getTableName() . '` `m`'
            . ' WHERE `m`.`messageType` = :toolCallType AND `m`.`truncatedAt` IS NULL AND (' . implode(' OR ', $stepConditions) . ')'
            . ') `steps` WHERE `stepRank` <= :stepsLimit ORDER BY `aiConversationId` ASC, `stepRank` ASC',
            $stepParameters,
            ['stepsLimit' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
        $newestStepsByChildId = [];
        $stepCountByChildId = [];
        foreach ($stepRows as $stepRow) {
            $childId = (int)$stepRow['aiConversationId'];
            $stepCountByChildId[$childId] = (int)$stepRow['stepCount'];
            $newestStepsByChildId[$childId][] = AgentSubAgentLiveStep::forToolCall(
                (string)($stepRow['toolName'] ?? ''),
                (string)($stepRow['toolCallStatus'] ?? ''),
                is_string($stepRow['created'] ?? null) ? DateTime::fromString($stepRow['created']) : null
            );
        }
        foreach ($childRows as $childRow) {
            $childId = (int)$childRow['id'];
            $agentSubAgentLiveActivity = AgentSubAgentLiveActivity::forChild(
                $childId,
                (string)$childRow['runState'],
                is_string($childRow['runHeartbeatAt'] ?? null) ? DateTime::fromString($childRow['runHeartbeatAt']) : null,
                $stepCountByChildId[$childId] ?? 0,
                $newestStepsByChildId[$childId] ?? []
            );
            $agentSubAgentLiveActivities->add($agentSubAgentLiveActivity);
        }
        return $agentSubAgentLiveActivities;
    }
}
