<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionThreadModel;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionApprovalRequestModel;
use DDD\Domain\AI\Repo\DB\Connections\DBAgentConnectionActionModel;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSteps;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTree;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversation;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversations;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTrees;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Domain\AI\Repo\DB\Conversations\A2A\DBAIConversationTaskPushNotificationConfigModel;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationDelegationBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\Agent\DBAIConversationToolCallBatchModel;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddingModel;
use DDD\Domain\AI\Repo\DB\Conversations\Schedules\DBAIConversationScheduleModel;
use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEventModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventModel;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases\DBAgentEvalSuiteCaseModel;
use DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandateModel;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCallModel;
use DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\AI\Repo\DB\Conversations\Subjects\DBAIConversationSubjectBindingModel;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Throwable;

/**
 * Deletes the conversations of the evaluation accounts whose last activity is older than an age limit, together with
 * every row that depends on them — politely, on a database the turn workers write all the time
 * (`app:agent:eval-conversations-purge`).
 *
 * The unit is a conversation TREE ({@see AIConversationEvalTree}): the root, its delegation children and the supervisor
 * tandems. A tree goes only when no conversation of it has a run in flight, a fresh heartbeat or activity younger than
 * the age limit. What depends on a tree is the ordered step list {@see buildPurgeSteps()}: the module's steps
 * ({@see buildModulePurgeSteps()}) and the application's ({@see buildHostDependantPurgeSteps()}), in the order of
 * {@see getPurgeStepKeysInOrder()}. Conversation-scoped records are deleted, records that outlive the conversation
 * (schedules, mandates' message pointers) keep their content and lose only the pointer. The conversation rows go last,
 * so a stopped batch never cascades a large remainder in one statement. {@see getColumnsNotPurged()} names every
 * conversation-pointing column that is deliberately no step.
 *
 * Polite by construction: every write is one limited statement (50 rows for the large tables, 500 for the others) in
 * a transaction of its own with a lock wait limit of {@see LOCK_WAIT_TIMEOUT_SECONDS} and a statement limit of
 * {@see STATEMENT_TIMEOUT_SECONDS} (a locked chunk is rolled back and stops the batch, the next run retries it); every
 * read statement runs in a transaction of its own with the same statement limit ({@see beginStatementLimitedRead()},
 * {@see endStatementLimitedRead()}); a pause after every write that doubles while writes are slow; on MariaDB a wait
 * while the server is busy (the load guard answers "not busy" on PostgreSQL); a time budget below a cron ticker's
 * process timeout. Nothing stays on the connection after a read or a chunk: the transaction is ended and the limits
 * are cleared. It resumes by construction: what was deleted is gone, the next run starts with what
 * is left. A dry run (the default) counts the rows per step and estimates their bytes.
 *
 * Scope: the statements run on the connection directly, so no repository read rights apply to them. What a run may
 * touch is decided here instead: only the conversations of the configured evaluation accounts
 * ({@see resolveAccountIdsToPurge()} refuses any other account), and with {@see AIConversationEvalPurgeSettings::$evalOnly}
 * only the trees whose root carries an evaluation marker.
 *
 * The cutoff moments are computed here in UTC and bound as parameters; every table and column name is quoted by the
 * database's dialect, so the statements run on MariaDB and PostgreSQL.
 *
 * Override points of an application (a same-path subclass): its own steps ({@see buildHostDependantPurgeSteps()}),
 * their order ({@see getPurgeStepKeysInOrder()}), the ids its steps match ({@see collectHostPurgeKeys()}), its own
 * evaluation markers ({@see buildHostEvalMarkerConditionsByMarker()}), the accounts whose marked trees only may go
 * ({@see findAccountIdsAllowedForEvalOnlyPurge()}), its columns that are no step ({@see getColumnsNotPurged()}).
 */
class AIConversationEvalPurgeService extends Service
{
    /** @var string Evaluation marker: the root carries `runSettings.evalRun`. */
    public const string EVAL_MARKER_EVAL_RUN = 'evalRun';

    /** @var string Evaluation marker: the root is a past conversation an evaluation seeded (`runSettings.evalSeededHistory`). */
    public const string EVAL_MARKER_SEEDED_HISTORY = 'evalSeededHistory';

    /** @var string Every write chunk of the step was written. */
    public const string STEP_RESULT_DONE = 'done';

    /** @var string A chunk hit a lock wait or a deadlock — the batch stops, the next run retries it. */
    public const string STEP_RESULT_LOCK_SKIPPED = 'lockSkipped';

    /** @var string The time budget ran out inside the step. */
    public const string STEP_RESULT_TIME_BUDGET = 'timeBudget';

    /** @var int How long one write waits for a locked row before its chunk is rolled back (seconds). */
    public const int LOCK_WAIT_TIMEOUT_SECONDS = 2;

    /** @var int How long one statement — a write or a read — may run (seconds). */
    public const int STATEMENT_TIMEOUT_SECONDS = 20;

    /** @var string The conversation alias of the purge's own conversation reads */
    public const string CONVERSATION_ALIAS = 'c';

    /** @var string The alias of the root row a candidate's root id points at */
    public const string ROOT_CONVERSATION_ALIAS = 'r';

    /** @var int Upper bound of the adaptive pause between two writes (milliseconds). */
    protected const int MAX_PAUSE_MS = 10000;

    /** @var int Pause while the server is busy (milliseconds). */
    protected const int SERVER_BUSY_PAUSE_MS = 5000;

    /** @var int Rounds of the tree expansion (children of tandems of children …); real trees need two. */
    protected const int TREE_EXPANSION_MAX_ROUNDS = 8;

    /** @var int The length of a stored moment `Y-m-d H:i:s`; a longer value (fractional seconds) is cut to it */
    protected const int STORED_MOMENT_LENGTH = 19;

    /**
     * @var int[] The transaction nesting level of every open statement-limited read, the innermost last: an end
     *     finishes only the transaction its begin opened, never one of a caller. Never handed out.
     */
    protected array $statementLimitedReadNestingLevels = [];

    /**
     * The configured evaluation accounts — every conversation of them counts as evaluation data
     * ({@see AgentEvalAccountsService::getEvalAccountIds()}).
     *
     * @return int[]
     */
    public function findConfiguredEvalAccountIds(): array
    {
        /** @var AgentEvalAccountsService $agentEvalAccountsService */
        $agentEvalAccountsService = DDDService::instance()->getService(AgentEvalAccountsService::class);
        return array_values(array_map('intval', $agentEvalAccountsService->getEvalAccountIds()));
    }

    /**
     * The accounts outside the configured evaluation accounts a run may name with
     * {@see AIConversationEvalPurgeSettings::$evalOnly}: accounts that also hold conversations which are no
     * evaluation, so only their marked trees ever go. The default: none.
     *
     * @return int[]
     */
    public function findAccountIdsAllowedForEvalOnlyPurge(): array
    {
        return [];
    }

    /**
     * The accounts one run purges: the ones the settings name — each must be a configured evaluation account, or with
     * {@see AIConversationEvalPurgeSettings::$evalOnly} one of {@see findAccountIdsAllowedForEvalOnlyPurge()} — or
     * every configured evaluation account.
     *
     * @return int[]
     * @throws \InvalidArgumentException when the settings name an account the run may not purge
     */
    public function resolveAccountIdsToPurge(AIConversationEvalPurgeSettings $settings): array
    {
        $configuredEvalAccountIds = $this->findConfiguredEvalAccountIds();
        if ($settings->accountIds === null) {
            return $configuredEvalAccountIds;
        }
        $requestedAccountIds = array_values(array_unique(array_map('intval', $settings->accountIds)));
        $allowedAccountIds = $settings->evalOnly
            // only the marked evaluation trees go — the conversations of these accounts that are no evaluation carry no marker
            ? array_merge($configuredEvalAccountIds, $this->findAccountIdsAllowedForEvalOnlyPurge())
            : $configuredEvalAccountIds;
        $foreignAccountIds = array_values(array_diff($requestedAccountIds, $allowedAccountIds));
        if ($foreignAccountIds !== []) {
            throw new \InvalidArgumentException($this->buildRefusalOfForeignAccounts($foreignAccountIds, $settings));
        }
        return $requestedAccountIds;
    }

    /**
     * The text of the refusal of accounts a run may not purge: `Not a configured eval account: 5, 6 — its
     * conversations are not purged.`, with {@see AIConversationEvalPurgeSettings::$evalOnly} also naming the accounts
     * whose marked trees may go.
     *
     * @param int[] $foreignAccountIds
     */
    protected function buildRefusalOfForeignAccounts(array $foreignAccountIds, AIConversationEvalPurgeSettings $settings): string
    {
        return 'Not a configured eval account'
            . ($settings->evalOnly ? ' nor one whose eval-marked trees may be purged (' . implode(', ', $this->findAccountIdsAllowedForEvalOnlyPurge()) . ')' : '')
            . ': ' . implode(', ', $foreignAccountIds) . ' — its conversations are not purged.';
    }

    /**
     * Columns pointing at a conversation or its rows that are deliberately NOT a purge step, with the reason. An
     * application adds the columns of its own tables (`array_merge` with the parent's). The memory columns come from the
     * memory store ({@see AgentMemoryStoreService::getColumnsNotPurgedByEvalPurge()}), the goal columns from the goal
     * store ({@see AgentGoalStoreService::getColumnsNotPurgedByEvalPurge()}): a host store names its own.
     * The two columns of the voice call table are listed while that table is not in use, and are steps while it is
     * ({@see getVoiceCallColumnsNotPurged()}).
     *
     * @return string[] `table.column` → why it is not a step
     */
    public function getColumnsNotPurged(): array
    {
        $conversationTableName = (string)DBAIConversationModel::getTableName();
        $messageTableName = (string)DBAIConversationMessageModel::getTableName();
        $toolCallBatchTableName = (string)DBAIConversationToolCallBatchModel::getTableName();
        return [
            ...$this->getAgentMemoryStoreService()->getColumnsNotPurgedByEvalPurge(),
            ...$this->getAgentGoalStoreService()->getColumnsNotPurgedByEvalPurge(),
            ...$this->getVoiceCallColumnsNotPurged(),
            ...$this->getAgentConnectionColumnsNotPurged(),
            "{$conversationTableName}.rootConversationId" => 'inside the tree: the tree expansion follows it',
            "{$conversationTableName}.supervisedConversationId" => 'inside the tree: the tree expansion follows it',
            "{$conversationTableName}.delegatedFromMessageId" => 'inside the tree: the child conversation goes with it',
            "{$messageTableName}.aiConversationId" => 'the messages are collected by it and deleted by their id',
            "{$messageTableName}.supervisorTurnMessageId" => 'inside the tree: the message goes with its conversation',
            DBAIConversationTaskPushNotificationConfigModel::getTableName() . '.taskUserMessageId' => 'the config goes with its conversation',
            "{$toolCallBatchTableName}.lastSeenMessageId" => 'the batch goes with its conversation',
            "{$toolCallBatchTableName}.loadedCapabilitiesMessageId" => 'the batch goes with its conversation',
            "{$toolCallBatchTableName}.sourceUserMessageId" => 'the batch goes with its conversation',
            DBAIConversationDelegationBatchModel::getTableName() . '.spawnedByMessageId' => 'the batch goes with its parent conversation',
            DBAIConversationTraceEventModel::getTableName() . '.agentVoiceCallId' => 'the trace event goes with its conversation',
            DBAIConversationSubjectBindingModel::getTableName() . '.aiConversationId' => 'the subject row goes with its conversation through the foreign key (ON DELETE CASCADE); the table may not exist yet in a host before the DDL, so no statement names it',
            DBAgentEvalSuiteCaseModel::getTableName() . '.aiConversationId' => 'the stored case of a suite run keeps the id of the conversation it ran as a plain number; the record outlives the conversation',
        ];
    }

    /**
     * The two conversation pointers of the voice call table while that table is not in use
     * ({@see isVoiceCallTableInUse()}): nothing writes a call row then and the table need not exist, so a statement
     * on it would fail. While the table is in use the two are purge steps ({@see buildModulePurgeSteps()}) and
     * this list is empty.
     *
     * @return string[] `table.column` → why it is not a step
     */
    protected function getVoiceCallColumnsNotPurged(): array
    {
        if ($this->isVoiceCallTableInUse()) {
            return [];
        }
        $voiceCallTableName = (string)DBAgentVoiceCallModel::getTableName();
        $reason = 'the call table is not in use (voice is switched off, or calls are kept in a store of the application): no row is written and the table need not exist';
        return [
            "{$voiceCallTableName}.aiConversationId" => $reason,
            "{$voiceCallTableName}.startedByAgentInConversationId" => $reason,
        ];
    }

    /** The column through which the AgentConnections tables point at a conversation ({@see getAgentConnectionTableNames()}) */
    public const string AGENT_CONNECTIONS_CONVERSATION_COLUMN = 'aiConversationId';

    /**
     * The conversation pointers of the AgentConnections tables while AgentConnections is switched off
     * ({@see areAgentConnectionTablesInUse()}): nothing writes their rows then and the tables need not exist, so a
     * statement on them would fail. While it is on they are purge steps ({@see buildModulePurgeSteps()}).
     *
     * @return string[] `table.column` → why it is not a step
     */
    protected function getAgentConnectionColumnsNotPurged(): array
    {
        if ($this->areAgentConnectionTablesInUse()) {
            return [];
        }
        $columnsNotPurged = [];
        foreach ($this->getAgentConnectionTableNames() as $tableName) {
            $columnsNotPurged[$tableName . '.' . static::AGENT_CONNECTIONS_CONVERSATION_COLUMN] = 'AgentConnections is switched off (AI.agentConnections.enabled): no row is written and the table need not exist';
        }
        return $columnsNotPurged;
    }

    /**
     * The AgentConnections tables that point at a conversation: the audit, the approval requests, the chat threads
     *
     * @return string[]
     */
    protected function getAgentConnectionTableNames(): array
    {
        return [
            (string)DBAgentConnectionActionModel::getTableName(),
            (string)DBAgentConnectionApprovalRequestModel::getTableName(),
            (string)DBAgentConnectionThreadModel::getTableName(),
        ];
    }

    /** Whether AgentConnections is switched on, so its tables exist ({@see AgentConnectionsConfiguration::isEnabled()}) */
    protected function areAgentConnectionTablesInUse(): bool
    {
        return AgentConnectionsConfiguration::current()->isEnabled();
    }

    /**
     * Whether voice calls are kept in the package's call table ({@see AgentVoiceConfiguration::isCallTableInUse()}).
     * An application that keeps its calls in a store of its own purges them in {@see buildHostDependantPurgeSteps()}.
     */
    protected function isVoiceCallTableInUse(): bool
    {
        return AgentVoiceConfiguration::current()->isCallTableInUse();
    }

    /**
     * The ordered statement list of one batch: the module's steps and the application's, in the order of
     * {@see getPurgeStepKeysInOrder()}. Order matters: children of a table go before the table, every message pointer
     * before the messages, the conversations last.
     */
    public function buildPurgeSteps(): AIConversationEvalPurgeSteps
    {
        return $this->buildHostDependantPurgeSteps()
            ->buildMergedWith($this->buildModulePurgeSteps())
            ->buildOrderedByStepKeys($this->getPurgeStepKeysInOrder());
    }

    /**
     * The order of the step list by step key ({@see AIConversationEvalPurgeStep::getStepKey()}); a step whose key the
     * list does not name keeps its place after the listed ones. The default: the application's steps, then the
     * module's (every application step points at a module row and goes before it; the messages and the conversations
     * stay last). An application that must interleave its steps with the module's returns the whole order.
     *
     * @return string[]
     */
    public function getPurgeStepKeysInOrder(): array
    {
        return array_merge($this->buildHostDependantPurgeSteps()->getStepKeys(), $this->buildModulePurgeSteps()->getStepKeys());
    }

    /**
     * The steps of the application's tables that point at the conversations, their messages or the module's other
     * rows (a kind of {@see AIConversationEvalPurgeKeys} or a kind of the application's own, filled by
     * {@see collectHostPurgeKeys()}). The default: none.
     */
    protected function buildHostDependantPurgeSteps(): AIConversationEvalPurgeSteps
    {
        return AIConversationEvalPurgeSteps::newInstance();
    }

    /** The steps of the module's tables, in their order: per-conversation records, pointers, messages, conversations. */
    protected function buildModulePurgeSteps(): AIConversationEvalPurgeSteps
    {
        $conversation = AIConversationEvalPurgeKeys::KIND_CONVERSATION;
        $message = AIConversationEvalPurgeKeys::KIND_MESSAGE;
        $mandateTableName = (string)DBAgentMandateModel::getTableName();
        $voiceCallTableName = (string)DBAgentVoiceCallModel::getTableName();
        $voiceCallTableIsInUse = $this->isVoiceCallTableInUse();
        $moduleSteps = AIConversationEvalPurgeSteps::newInstance();
        // per-conversation records
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows((string)DBAIConversationTurnEventModel::getTableName(), 'aiConversationId', $conversation));
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows((string)DBAIConversationTraceEventModel::getTableName(), 'aiConversationId', $conversation));
        if ($this->areAgentConnectionTablesInUse()) {
            // AgentConnections: the audit, approval requests and chat threads of an eval conversation (FAKE connections only)
            foreach ($this->getAgentConnectionTableNames() as $tableName) {
                $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows($tableName, static::AGENT_CONNECTIONS_CONVERSATION_COLUMN, $conversation));
            }
        }
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows((string)DBAIConversationToolCallBatchModel::getTableName(), 'conversationId', $conversation));
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows((string)DBAIConversationDelegationBatchModel::getTableName(), 'parentConversationId', $conversation));
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows((string)DBAIConversationTaskPushNotificationConfigModel::getTableName(), 'aiConversationId', $conversation));
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows((string)DBAIConversationCompactionNodeModel::getTableName(), 'aiConversationId', $conversation, hasHeavyRows: true));
        if ($voiceCallTableIsInUse) {
            // the voice calls that ran on the conversation (their trace events went with the conversation above)
            $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows($voiceCallTableName, 'aiConversationId', $conversation));
        }
        // records that exist because of the conversation run
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows($mandateTableName, 'sourceConversationId', $conversation));
        // records that outlive the conversation: the pointer goes, the record stays
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::nullifyColumn((string)DBAIConversationScheduleModel::getTableName(), 'createdByConversationId', $conversation));
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::nullifyColumn($mandateTableName, 'sourceUserMessageId', $message));
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::nullifyColumn($mandateTableName, 'revokedByMessageId', $message));
        if ($voiceCallTableIsInUse) {
            // a call the agent started from the conversation and that ran on another one
            $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::nullifyColumn($voiceCallTableName, 'startedByAgentInConversationId', $conversation));
        }
        // the messages, their embeddings first
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows((string)DBAIConversationMessageEmbeddingModel::getTableName(), 'aiConversationMessageId', $message, hasHeavyRows: true));
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows((string)DBAIConversationMessageModel::getTableName(), 'id', $message, hasHeavyRows: true));
        // the conversations themselves — last
        $this->addPurgeStep($moduleSteps, AIConversationEvalPurgeStep::deleteRows((string)DBAIConversationModel::getTableName(), 'id', $conversation, hasHeavyRows: true));
        return $moduleSteps;
    }

    /** Appends one step to a step list (the set takes a variable). */
    protected function addPurgeStep(AIConversationEvalPurgeSteps $steps, AIConversationEvalPurgeStep $step): void
    {
        $steps->add($step);
    }

    /**
     * Delete (or, in a dry run, count) the evaluation conversation trees older than the age limit, batch by batch,
     * until no tree is left or the time budget is used up.
     *
     * @param AIConversationProgressReporterInterface|null $progressReporter one line per batch, for the command output
     * @throws \Doctrine\DBAL\Exception
     */
    public function purgeEvalConversations(AIConversationEvalPurgeSettings $settings, ?AIConversationProgressReporterInterface $progressReporter = null): AIConversationEvalPurgeReport
    {
        $startedAtSeconds = $this->currentSeconds();
        $report = AIConversationEvalPurgeReport::forRun($settings->dryRun);
        $evalAccountIds = $this->resolveAccountIdsToPurge($settings);
        if ($evalAccountIds === []) {
            $report->stoppedBecause = AIConversationEvalPurgeReport::STOPPED_NO_EVAL_ACCOUNTS;
            return $report;
        }
        // the columns hold UTC moments; unix seconds are zone-free and the settings render them as UTC
        $nowUnixTimestamp = $this->currentUnixTimestamp();
        $activityCutoff = $settings->buildActivityCutoff($nowUnixTimestamp);
        $freshHeartbeatCutoff = $settings->buildFreshHeartbeatCutoff($nowUnixTimestamp);
        $report->activityCutoff = $activityCutoff;
        $steps = $this->buildPurgeSteps();
        $averageRowBytesByTableName = $this->findAverageRowBytesByTableName($steps);
        $afterRootConversationId = $settings->afterRootConversationId ?? 0;
        $report->currentPauseMs = max(0, $settings->pauseMs);

        while (true) {
            if ($this->isTimeBudgetUsedUp($startedAtSeconds, $settings)) {
                $report->stoppedBecause = AIConversationEvalPurgeReport::STOPPED_TIME_BUDGET;
                break;
            }
            if (!$settings->dryRun && $this->isDatabaseTooBusy($settings)) {
                $report->pausesForServerLoad++;
                $this->pauseMilliseconds(static::SERVER_BUSY_PAUSE_MS);
                continue;
            }
            $rootConversationIds = $this->findCandidateRootConversationIds($evalAccountIds, $activityCutoff, $afterRootConversationId, $settings);
            if ($rootConversationIds === []) {
                break;
            }
            $treesToPurge = $this->findTreesToPurge($rootConversationIds, $evalAccountIds, $activityCutoff, $freshHeartbeatCutoff, $settings, $report, $startedAtSeconds);
            $afterRootConversationId = (int)$report->lastRootConversationId;
            $batchResult = $this->purgeTreesOfBatch($treesToPurge, $steps, $settings, $report, $startedAtSeconds, $averageRowBytesByTableName);
            if ($batchResult === static::STEP_RESULT_TIME_BUDGET) {
                $report->stoppedBecause = AIConversationEvalPurgeReport::STOPPED_TIME_BUDGET;
                $progressReporter?->reportProgress($report->buildProgressLine());
                break;
            }
            $progressReporter?->reportProgress($report->buildProgressLine());
            if ($settings->onlyRootConversationId !== null) {
                break;
            }
        }
        $report->runSeconds = (int)round($this->currentSeconds() - $startedAtSeconds);
        return $report;
    }

    /**
     * Checks the trees of one batch's roots and returns those to purge; every verdict is counted on the report, and
     * the report's {@see AIConversationEvalPurgeReport::$lastRootConversationId} becomes the resume point of the next
     * batch: the last root, or the root before the first one left unchecked when the time budget ran out.
     *
     * @param int[] $rootConversationIds
     * @param int[] $evalAccountIds
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findTreesToPurge(
        array $rootConversationIds,
        array $evalAccountIds,
        string $activityCutoff,
        string $freshHeartbeatCutoff,
        AIConversationEvalPurgeSettings $settings,
        AIConversationEvalPurgeReport $report,
        float $startedAtSeconds
    ): AIConversationEvalTrees {
        $afterRootConversationId = max($rootConversationIds);
        $treesToPurge = AIConversationEvalTrees::newInstance();
        foreach ($rootConversationIds as $rootConversationId) {
            // the checks of a large batch take seconds over a slow link — the trees checked so far are the batch
            if ($this->isTimeBudgetUsedUp($startedAtSeconds, $settings)) {
                $afterRootConversationId = $rootConversationId - 1;
                break;
            }
            $tree = $this->findEvalTree($rootConversationId, $settings);
            $verdict = $tree->decidePurgeVerdict($evalAccountIds, $activityCutoff, $freshHeartbeatCutoff);
            $report->recordVerdict($verdict);
            if ($verdict === AIConversationEvalTree::VERDICT_PURGE) {
                $treesToPurge->add($tree);
            }
        }
        $report->lastRootConversationId = $afterRootConversationId;
        return $treesToPurge;
    }

    /**
     * Runs the steps over the trees of one batch and counts them when every step finished; a batch a step stopped is
     * counted as left incomplete. Returns {@see STEP_RESULT_DONE} for a batch without trees.
     *
     * @param int[] $averageRowBytesByTableName
     * @throws \Doctrine\DBAL\Exception
     */
    protected function purgeTreesOfBatch(
        AIConversationEvalTrees $treesToPurge,
        AIConversationEvalPurgeSteps $steps,
        AIConversationEvalPurgeSettings $settings,
        AIConversationEvalPurgeReport $report,
        float $startedAtSeconds,
        array $averageRowBytesByTableName
    ): string {
        if ($treesToPurge->count() === 0) {
            return static::STEP_RESULT_DONE;
        }
        $evalMarkersByRootConversationId = $settings->evalOnly
            ? $this->findEvalMarkersOfRootConversations($treesToPurge->getRootConversationIds())
            : [];
        $purgeKeys = $this->buildPurgeKeys($treesToPurge, $settings);
        // read before the messages go, erased only once every step of the batch finished
        $toolCallImageReferences = $settings->dryRun ? [] : $this->findStoredToolCallImageReferencesOfMessages($purgeKeys->getMessageIds(), $settings);
        $batchResult = $this->runPurgeSteps($steps, $purgeKeys, $settings, $report, $startedAtSeconds, $averageRowBytesByTableName);
        if ($batchResult === static::STEP_RESULT_DONE) {
            $report->toolPicturesErased += $this->eraseStoredToolCallImages($toolCallImageReferences);
            $report->recordPurgedTrees($treesToPurge, $purgeKeys, $evalMarkersByRootConversationId);
        } else {
            $report->batchesLeftIncomplete++;
        }
        return $batchResult;
    }

    /**
     * The stored pictures of the tool calls of messages (references to the picture store; inline pictures have
     * nothing stored). Never throws: a failed read leaves the pictures to the bucket's lifecycle rule.
     *
     * @param int[] $messageIds
     * @return ToolCallImageReference[]
     */
    protected function findStoredToolCallImageReferencesOfMessages(array $messageIds, AIConversationEvalPurgeSettings $settings): array
    {
        $toolCallImageReferences = [];
        $toolCallsSql = $this->buildToolCallsOfMessagesSql();
        try {
            foreach (array_chunk($messageIds, max(1, $settings->idsPerStatement)) as $messageIdChunk) {
                $this->beginStatementLimitedRead();
                try {
                    $toolCallJsons = $this->getConnection()->fetchFirstColumn(
                        $toolCallsSql,
                        ['messageIds' => $messageIdChunk, 'messageType' => AIConversationMessage::MESSAGE_TOOL_CALL],
                        ['messageIds' => ArrayParameterType::INTEGER]
                    );
                } finally {
                    $this->endStatementLimitedRead();
                }
                foreach ($toolCallJsons as $toolCallJson) {
                    foreach (static::extractStoredToolCallImageReferences($toolCallJson) as $toolCallImageReference) {
                        $toolCallImageReferences[] = $toolCallImageReference;
                    }
                }
            }
        } catch (Throwable $readFailure) {
            DDDService::instance()->getLogger()->warning('[AgentEvalPurge] tool pictures not read: ' . $readFailure->getMessage());
        }
        return $toolCallImageReferences;
    }

    /** The read of {@see findStoredToolCallImageReferencesOfMessages()}; bound parameters `:messageIds`, `:messageType`. */
    public function buildToolCallsOfMessagesSql(): string
    {
        return 'SELECT ' . $this->quoteName('toolCall') . ' FROM ' . $this->quoteName((string)DBAIConversationMessageModel::getTableName())
            . ' WHERE ' . $this->quoteName('id') . ' IN (:messageIds) AND ' . $this->quoteName('messageType') . ' = :messageType'
            . ' AND ' . $this->quoteName('toolCall') . ' IS NOT NULL';
    }

    /**
     * The references of a stored tool call JSON that name the picture store (`imageReferences`); none for a value
     * that is no tool call.
     *
     * @return ToolCallImageReference[]
     */
    public static function extractStoredToolCallImageReferences(mixed $toolCallJson): array
    {
        $toolCall = is_string($toolCallJson) ? json_decode($toolCallJson, true) : null;
        $imageReferences = is_array($toolCall) ? ($toolCall['imageReferences'] ?? null) : null;
        $toolCallImageReferences = [];
        foreach (is_array($imageReferences) ? $imageReferences : [] as $imageReference) {
            if (!is_array($imageReference) || ($imageReference['sourceStore'] ?? null) !== ToolCallImageReference::SOURCE_STORE_SPOOL
                || !is_string($imageReference['sourceImageId'] ?? null) || $imageReference['sourceImageId'] === ''
            ) {
                continue;
            }
            $toolCallImageReference = new ToolCallImageReference();
            $toolCallImageReference->sourceStore = ToolCallImageReference::SOURCE_STORE_SPOOL;
            $toolCallImageReference->sourceImageId = $imageReference['sourceImageId'];
            $toolCallImageReference->sha256 = is_string($imageReference['sha256'] ?? null) ? $imageReference['sha256'] : '';
            $toolCallImageReferences[] = $toolCallImageReference;
        }
        return $toolCallImageReferences;
    }

    /**
     * Erases the stored pictures of purged tool calls ({@see AgentToolCallImageStoreService::eraseStoredPicturesOfReferences()}).
     *
     * @param ToolCallImageReference[] $toolCallImageReferences
     * @return int the objects deleted
     */
    protected function eraseStoredToolCallImages(array $toolCallImageReferences): int
    {
        if ($toolCallImageReferences === []) {
            return 0;
        }
        try {
            /** @var AgentToolCallImageStoreService $agentToolCallImageStoreService */
            $agentToolCallImageStoreService = DDDService::instance()->getService(AgentToolCallImageStoreService::class);
            return $agentToolCallImageStoreService->eraseStoredPicturesOfReferences($toolCallImageReferences);
        } catch (Throwable $eraseFailure) {
            DDDService::instance()->getLogger()->warning('[AgentEvalPurge] tool pictures not erased: ' . $eraseFailure->getMessage());
            return 0;
        }
    }

    /**
     * Does nothing. The limits are set per statement: on the transaction of every write chunk ({@see executeStepChunk()}:
     * {@see LOCK_WAIT_TIMEOUT_SECONDS} for a locked row, {@see STATEMENT_TIMEOUT_SECONDS} for the statement) and of
     * every read ({@see beginStatementLimitedRead()}), and cleared when that transaction ends; the purge no longer
     * calls this method.
     *
     * @deprecated does nothing since the limits sit on each write chunk and each read; it will be removed in a later
     *     release — drop the call.
     */
    public function preparePoliteWriteSession(): void
    {
    }

    /**
     * Opens a transaction on the purge's connection for ONE read and limits its statements to
     * {@see STATEMENT_TIMEOUT_SECONDS} through the dialect; the lock wait is left as it is. Pair it with
     * {@see endStatementLimitedRead()} in a `finally`: the purge brackets every read statement of a run this way, and
     * an application brackets its own reads of a purge run the same way.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function beginStatementLimitedRead(): void
    {
        $connection = $this->getConnection();
        $connection->beginTransaction();
        $this->statementLimitedReadNestingLevels[] = (int)$connection->getTransactionNestingLevel();
        $this->getSqlDialect()->setTransactionTimeouts($connection, null, static::STATEMENT_TIMEOUT_SECONDS);
    }

    /**
     * Ends the transaction of the last {@see beginStatementLimitedRead()} — a commit, or a rollback when it can no
     * longer commit — and clears the limits through the dialect once no such read is open any more. Safe in a
     * `finally` also when the begin failed half-way: a transaction the begin did not open is never touched.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function endStatementLimitedRead(): void
    {
        $connection = $this->getConnection();
        $nestingLevelOfTheRead = array_pop($this->statementLimitedReadNestingLevels);
        try {
            if ($nestingLevelOfTheRead !== null && $connection->isTransactionActive() && (int)$connection->getTransactionNestingLevel() >= $nestingLevelOfTheRead) {
                $this->finishStatementLimitedReadTransaction($connection);
            }
        } finally {
            if ($this->statementLimitedReadNestingLevels === []) {
                $this->getSqlDialect()->clearTransactionTimeouts($connection);
            }
        }
    }

    /**
     * Commits the transaction of a read; rolls it back when it is marked rollback-only or the commit fails (a read
     * that failed on PostgreSQL leaves its transaction aborted).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function finishStatementLimitedReadTransaction(Connection $connection): void
    {
        if ($connection->isRollbackOnly()) {
            $connection->rollBack();
            return;
        }
        try {
            $connection->commit();
        } catch (Throwable) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    /**
     * Run every step over the batch's ids. Returns {@see STEP_RESULT_DONE} when all of them finished, otherwise the
     * result of the step that stopped — the later steps (the messages, the conversations) are not touched then.
     *
     * @param int[] $averageRowBytesByTableName
     */
    public function runPurgeSteps(
        AIConversationEvalPurgeSteps $steps,
        AIConversationEvalPurgeKeys $purgeKeys,
        AIConversationEvalPurgeSettings $settings,
        AIConversationEvalPurgeReport $report,
        float $startedAtSeconds,
        array $averageRowBytesByTableName
    ): string {
        foreach ($steps->getElements() as $step) {
            $stepIds = $purgeKeys->getIdsOfKind($step->keyKind);
            if ($stepIds === []) {
                continue;
            }
            $averageRowBytes = (int)($averageRowBytesByTableName[$step->tableName] ?? 0);
            if ($settings->dryRun) {
                foreach (array_chunk($stepIds, max(1, $settings->idsPerStatement)) as $idChunk) {
                    $report->addStepRows($step, $this->countStepRows($step, $idChunk), $averageRowBytes);
                }
                continue;
            }
            $stepResult = $this->runStepInChunks($step, $stepIds, $settings, $report, $startedAtSeconds, $averageRowBytes);
            if ($stepResult !== static::STEP_RESULT_DONE) {
                return $stepResult;
            }
        }
        return static::STEP_RESULT_DONE;
    }

    /**
     * Write one step: per id chunk, repeat the limited statement until it writes fewer rows than its limit. Pauses after
     * every write (doubled after a slow one, halved back after a fast one), waits while the server is busy, stops on a
     * lock wait or when the time budget is used up.
     *
     * @param int[] $stepIds
     */
    public function runStepInChunks(
        AIConversationEvalPurgeStep $step,
        array $stepIds,
        AIConversationEvalPurgeSettings $settings,
        AIConversationEvalPurgeReport $report,
        float $startedAtSeconds,
        int $averageRowBytes
    ): string {
        $rowLimit = $step->getRowLimit($settings);
        foreach (array_chunk($stepIds, max(1, $settings->idsPerStatement)) as $idChunk) {
            do {
                if ($this->isTimeBudgetUsedUp($startedAtSeconds, $settings)) {
                    return static::STEP_RESULT_TIME_BUDGET;
                }
                while ($this->isDatabaseTooBusy($settings)) {
                    $report->pausesForServerLoad++;
                    $this->pauseMilliseconds(static::SERVER_BUSY_PAUSE_MS);
                    if ($this->isTimeBudgetUsedUp($startedAtSeconds, $settings)) {
                        return static::STEP_RESULT_TIME_BUDGET;
                    }
                }
                $writeStartedAtSeconds = $this->currentSeconds();
                try {
                    $writtenRows = $this->executeStepChunk($step, $idChunk, $rowLimit);
                } catch (LockWaitTimeoutException|DeadlockException) {
                    $report->chunksSkippedOnLock++;
                    return static::STEP_RESULT_LOCK_SKIPPED;
                }
                $writeMs = (int)round(($this->currentSeconds() - $writeStartedAtSeconds) * 1000);
                $report->recordWrite($writeMs);
                $report->addStepRows($step, $writtenRows, $averageRowBytes);
                $report->currentPauseMs = $writeMs > max(1, $settings->slowWriteMs)
                    ? min(static::MAX_PAUSE_MS, max(1, $report->currentPauseMs) * 2)
                    : max($settings->pauseMs, intdiv($report->currentPauseMs, 2));
                $this->pauseMilliseconds($report->currentPauseMs);
            } while ($writtenRows >= $rowLimit);
        }
        return static::STEP_RESULT_DONE;
    }

    /**
     * The roots of the next batch: conversations of the evaluation accounts after `$afterRootConversationId` that are
     * a root (no root id, not a supervisor tandem) or a child whose root row is already gone, created before the
     * cutoff. The full activity check is the tree's.
     *
     * @param int[] $evalAccountIds
     * @return int[]
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findCandidateRootConversationIds(array $evalAccountIds, string $activityCutoff, int $afterRootConversationId, AIConversationEvalPurgeSettings $settings): array
    {
        $queryParameters = ['accountIds' => array_values(array_map('intval', $evalAccountIds)), 'afterId' => $afterRootConversationId, 'activityCutoff' => $activityCutoff];
        $queryParameterTypes = ['accountIds' => ArrayParameterType::INTEGER];
        if ($settings->onlyRootConversationId !== null) {
            $queryParameters['onlyRootConversationId'] = $settings->onlyRootConversationId;
        }
        $keptAgentSpaceIds = $this->getKeptAgentSpaceIds($settings);
        if ($keptAgentSpaceIds !== []) {
            $queryParameters['keptAgentSpaceIds'] = $keptAgentSpaceIds;
            $queryParameterTypes['keptAgentSpaceIds'] = ArrayParameterType::INTEGER;
        }
        $this->beginStatementLimitedRead();
        try {
            $candidateRootConversationIds = $this->getConnection()->fetchFirstColumn(
                $this->buildCandidateRootConversationsSql($settings),
                $queryParameters,
                $queryParameterTypes
            );
        } finally {
            $this->endStatementLimitedRead();
        }
        return array_map('intval', $candidateRootConversationIds);
    }

    /**
     * @return int[] the agent spaces of {@see AIConversationEvalPurgeSettings::$keptAgentSpaceIds}: positive ids, each once
     */
    protected function getKeptAgentSpaceIds(AIConversationEvalPurgeSettings $settings): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $settings->keptAgentSpaceIds), static fn (int $agentSpaceId): bool => $agentSpaceId > 0)));
    }

    /**
     * The query of the next batch's roots ({@see findCandidateRootConversationIds()}): bound parameters `:accountIds`,
     * `:afterId`, `:activityCutoff`, and `:onlyRootConversationId` / `:keptAgentSpaceIds` when the settings name them.
     * Restricted to roots with an evaluation marker when the run is {@see AIConversationEvalPurgeSettings::$evalOnly};
     * a conversation on a kept agent space is never a candidate (one at account level is on no space).
     */
    public function buildCandidateRootConversationsSql(AIConversationEvalPurgeSettings $settings): string
    {
        $conversationTableName = $this->quoteName((string)DBAIConversationModel::getTableName());
        $conversationAlias = $this->quoteName(static::CONVERSATION_ALIAS);
        $rootAlias = $this->quoteName(static::ROOT_CONVERSATION_ALIAS);
        $conversationIdSql = $this->quoteColumn(static::CONVERSATION_ALIAS, 'id');
        $agentSpaceIdSql = $this->quoteColumn(static::CONVERSATION_ALIAS, 'agentSpaceId');
        $onlyRootClause = $settings->onlyRootConversationId !== null ? " AND {$conversationIdSql} = :onlyRootConversationId" : '';
        $evalOnlyClause = $settings->evalOnly ? ' AND ' . $this->buildEvalMarkerSql(static::CONVERSATION_ALIAS) . ' IS NOT NULL' : '';
        $keptAgentSpacesClause = $this->getKeptAgentSpaceIds($settings) !== []
            ? " AND ({$agentSpaceIdSql} IS NULL OR {$agentSpaceIdSql} NOT IN (:keptAgentSpaceIds))"
            : '';
        return "SELECT {$conversationIdSql} FROM {$conversationTableName} {$conversationAlias}"
            . " LEFT JOIN {$conversationTableName} {$rootAlias} ON " . $this->quoteColumn(static::ROOT_CONVERSATION_ALIAS, 'id') . ' = ' . $this->quoteColumn(static::CONVERSATION_ALIAS, 'rootConversationId')
            . ' WHERE ' . $this->quoteColumn(static::CONVERSATION_ALIAS, 'accountId') . " IN (:accountIds) AND {$conversationIdSql} > :afterId"
            . ' AND ' . $this->quoteColumn(static::CONVERSATION_ALIAS, 'supervisedConversationId') . ' IS NULL'
            . ' AND (' . $this->quoteColumn(static::CONVERSATION_ALIAS, 'rootConversationId') . ' IS NULL OR ' . $this->quoteColumn(static::ROOT_CONVERSATION_ALIAS, 'id') . ' IS NULL)'
            . ' AND ' . $this->quoteColumn(static::CONVERSATION_ALIAS, 'created') . ' < :activityCutoff'
            . $onlyRootClause . $evalOnlyClause . $keptAgentSpacesClause
            . " ORDER BY {$conversationIdSql} LIMIT " . max(1, $settings->treesPerBatch);
    }

    /**
     * The evaluation marker of a root conversation as an SQL expression — NULL for a conversation that is no
     * evaluation data. The markers are checked in the order of {@see buildEvalMarkerConditionsByMarker()}; the
     * owner's own conversations carry none.
     */
    public function buildEvalMarkerSql(string $conversationAlias): string
    {
        $markerCases = [];
        foreach ($this->buildEvalMarkerConditionsByMarker($conversationAlias) as $evalMarker => $markerConditionSql) {
            $markerCases[] = "WHEN {$markerConditionSql} THEN " . $this->buildStringLiteral((string)$evalMarker);
        }
        return '(CASE ' . implode(' ', $markerCases) . ' ELSE NULL END)';
    }

    /**
     * The conditions of the evaluation markers, in the order they are checked: the module's two run-settings markers
     * ({@see EVAL_MARKER_EVAL_RUN} — every conversation an evaluation persists; {@see EVAL_MARKER_SEEDED_HISTORY} —
     * the past conversations an evaluation seeds), then the application's ({@see buildHostEvalMarkerConditionsByMarker()}).
     *
     * @return string[] marker → SQL condition over the conversation alias
     */
    public function buildEvalMarkerConditionsByMarker(string $conversationAlias): array
    {
        $sqlDialect = $this->getSqlDialect();
        $runSettingsSql = $this->quoteColumn($conversationAlias, 'runSettings');
        $markerConditionsByMarker = [
            static::EVAL_MARKER_EVAL_RUN => $sqlDialect->jsonBool($runSettingsSql, JsonPath::fromKeys('evalRun')),
            static::EVAL_MARKER_SEEDED_HISTORY => $sqlDialect->jsonBool($runSettingsSql, JsonPath::fromKeys('evalSeededHistory')),
        ];
        foreach ($this->buildHostEvalMarkerConditionsByMarker($conversationAlias) as $evalMarker => $markerConditionSql) {
            if (!isset($markerConditionsByMarker[$evalMarker])) {
                $markerConditionsByMarker[$evalMarker] = $markerConditionSql;
            }
        }
        return $markerConditionsByMarker;
    }

    /**
     * The application's own evaluation markers: a condition over the conversation alias that is true for a
     * conversation only an evaluation creates (e.g. one on an agent space an evaluation created). Checked after the
     * module's markers, in the order of the map. The default: none.
     *
     * @return string[] marker → SQL condition (quote names through {@see quoteName()} / {@see quoteColumn()})
     */
    protected function buildHostEvalMarkerConditionsByMarker(string $conversationAlias): array
    {
        return [];
    }

    /**
     * @param int[] $rootConversationIds
     * @return array<int, string|null> root conversation id → its evaluation marker
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findEvalMarkersOfRootConversations(array $rootConversationIds): array
    {
        $evalMarkersByRootConversationId = [];
        $this->beginStatementLimitedRead();
        try {
            $markerRows = $this->getConnection()->fetchAllAssociative(
                $this->buildEvalMarkersOfRootConversationsSql(),
                ['rootConversationIds' => array_values(array_map('intval', $rootConversationIds))],
                ['rootConversationIds' => ArrayParameterType::INTEGER]
            );
        } finally {
            $this->endStatementLimitedRead();
        }
        foreach ($markerRows as $markerRow) {
            $evalMarkersByRootConversationId[(int)$markerRow['rootConversationId']] = $markerRow['evalMarker'] !== null ? (string)$markerRow['evalMarker'] : null;
        }
        return $evalMarkersByRootConversationId;
    }

    /** The marker read of {@see findEvalMarkersOfRootConversations()}; bound parameter `:rootConversationIds`. */
    public function buildEvalMarkersOfRootConversationsSql(): string
    {
        $conversationIdSql = $this->quoteColumn(static::CONVERSATION_ALIAS, 'id');
        return "SELECT {$conversationIdSql} AS " . $this->quoteName('rootConversationId') . ', '
            . $this->buildEvalMarkerSql(static::CONVERSATION_ALIAS) . ' AS ' . $this->quoteName('evalMarker')
            . ' FROM ' . $this->quoteName((string)DBAIConversationModel::getTableName()) . ' ' . $this->quoteName(static::CONVERSATION_ALIAS)
            . " WHERE {$conversationIdSql} IN (:rootConversationIds)";
    }

    /**
     * The tree of one root: the conversations that name a tree member as their root or as the conversation they
     * supervise, repeated until nothing new is found; then their run facts and the newest message.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findEvalTree(int $rootConversationId, AIConversationEvalPurgeSettings $settings): AIConversationEvalTree
    {
        $conversationIds = $this->findTreeConversationIds($rootConversationId, $settings);
        return AIConversationEvalTree::fromConversations(
            $rootConversationId,
            $this->findTreeConversations($conversationIds, $settings),
            $this->findNewestMessageCreatedAt($conversationIds, $settings)
        );
    }

    /**
     * @return int[] the root and every conversation that names a member of the tree as its root or as the conversation
     *     it supervises, the root first
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findTreeConversationIds(int $rootConversationId, AIConversationEvalPurgeSettings $settings): array
    {
        $connection = $this->getConnection();
        $treeExpansionSql = $this->buildTreeExpansionSql();
        $conversationIds = [$rootConversationId];
        $frontierIds = [$rootConversationId];
        for ($expansionRound = 0; $expansionRound < static::TREE_EXPANSION_MAX_ROUNDS && $frontierIds !== []; $expansionRound++) {
            $foundIds = [];
            foreach (array_chunk($frontierIds, max(1, $settings->idsPerStatement)) as $frontierChunk) {
                $this->beginStatementLimitedRead();
                try {
                    $foundChunkIds = $connection->fetchFirstColumn(
                        $treeExpansionSql,
                        ['frontierIds' => $frontierChunk],
                        ['frontierIds' => ArrayParameterType::INTEGER]
                    );
                } finally {
                    $this->endStatementLimitedRead();
                }
                $foundIds = array_merge($foundIds, array_map('intval', $foundChunkIds));
            }
            $frontierIds = array_values(array_diff(array_unique($foundIds), $conversationIds));
            $conversationIds = array_merge($conversationIds, $frontierIds);
        }
        return $conversationIds;
    }

    /** One round of the tree expansion; bound parameter `:frontierIds` (used twice). */
    public function buildTreeExpansionSql(): string
    {
        return 'SELECT ' . $this->quoteName('id') . ' FROM ' . $this->quoteName((string)DBAIConversationModel::getTableName())
            . ' WHERE ' . $this->quoteName('rootConversationId') . ' IN (:frontierIds) OR ' . $this->quoteName('supervisedConversationId') . ' IN (:frontierIds)';
    }

    /**
     * The run facts and activity moments of the tree's conversations, read in chunks, in the order they were read.
     *
     * @param int[] $conversationIds
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findTreeConversations(array $conversationIds, AIConversationEvalPurgeSettings $settings): AIConversationEvalTreeConversations
    {
        $treeConversations = AIConversationEvalTreeConversations::newInstance();
        $treeConversationsSql = $this->buildTreeConversationsSql();
        foreach (array_chunk($conversationIds, max(1, $settings->idsPerStatement)) as $conversationChunk) {
            $this->beginStatementLimitedRead();
            try {
                $conversationRows = $this->getConnection()->fetchAllAssociative(
                    $treeConversationsSql,
                    ['conversationIds' => $conversationChunk],
                    ['conversationIds' => ArrayParameterType::INTEGER]
                );
            } finally {
                $this->endStatementLimitedRead();
            }
            foreach ($conversationRows as $conversationRow) {
                $treeConversation = AIConversationEvalTreeConversation::forConversation(
                    (int)$conversationRow['id'],
                    $conversationRow['accountId'] !== null ? (int)$conversationRow['accountId'] : null,
                    $conversationRow['runState'] !== null ? (string)$conversationRow['runState'] : null,
                    (bool)$conversationRow['hasRunToken']
                );
                foreach (AIConversationEvalTreeConversation::ACTIVITY_COLUMNS as $activityColumnName) {
                    $treeConversation->setActivityMoment($activityColumnName, $this->normalizeStoredMoment($conversationRow[$activityColumnName] ?? null));
                }
                $treeConversations->add($treeConversation);
            }
        }
        return $treeConversations;
    }

    /** The read of the tree's conversations; bound parameter `:conversationIds`. */
    public function buildTreeConversationsSql(): string
    {
        $selectedColumnSqls = [$this->quoteName('id'), $this->quoteName('accountId'), $this->quoteName('runState'),
            '(' . $this->quoteName('runToken') . ' IS NOT NULL) AS ' . $this->quoteName('hasRunToken')];
        foreach (AIConversationEvalTreeConversation::ACTIVITY_COLUMNS as $activityColumnName) {
            $selectedColumnSqls[] = $this->quoteName($activityColumnName);
        }
        return 'SELECT ' . implode(', ', $selectedColumnSqls) . ' FROM ' . $this->quoteName((string)DBAIConversationModel::getTableName())
            . ' WHERE ' . $this->quoteName('id') . ' IN (:conversationIds)';
    }

    /**
     * The newest `created` of the messages of the tree's conversations, or null when they have none.
     *
     * @param int[] $conversationIds
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findNewestMessageCreatedAt(array $conversationIds, AIConversationEvalPurgeSettings $settings): ?string
    {
        $newestMessageCreatedAt = null;
        $newestMessageSql = $this->buildNewestMessageSql();
        foreach (array_chunk($conversationIds, max(1, $settings->idsPerStatement)) as $conversationChunk) {
            $this->beginStatementLimitedRead();
            try {
                $storedNewestMessageCreatedAt = $this->getConnection()->fetchOne(
                    $newestMessageSql,
                    ['conversationIds' => $conversationChunk],
                    ['conversationIds' => ArrayParameterType::INTEGER]
                );
            } finally {
                $this->endStatementLimitedRead();
            }
            $chunkNewestMessageCreatedAt = $this->normalizeStoredMoment($storedNewestMessageCreatedAt);
            if ($chunkNewestMessageCreatedAt !== null && ($newestMessageCreatedAt === null || $chunkNewestMessageCreatedAt > $newestMessageCreatedAt)) {
                $newestMessageCreatedAt = $chunkNewestMessageCreatedAt;
            }
        }
        return $newestMessageCreatedAt;
    }

    /** The newest message of a chunk of conversations; bound parameter `:conversationIds`. */
    public function buildNewestMessageSql(): string
    {
        return 'SELECT MAX(' . $this->quoteName('created') . ') FROM ' . $this->quoteName((string)DBAIConversationMessageModel::getTableName())
            . ' WHERE ' . $this->quoteName('aiConversationId') . ' IN (:conversationIds)';
    }

    /**
     * A stored moment as the trees compare it: `Y-m-d H:i:s`, fractional seconds cut off; null for no value.
     */
    protected function normalizeStoredMoment(mixed $storedMoment): ?string
    {
        if ($storedMoment === null || $storedMoment === false || $storedMoment === '') {
            return null;
        }
        return substr((string)$storedMoment, 0, static::STORED_MOMENT_LENGTH);
    }

    /**
     * The ids the steps of one batch match: the trees' conversations, their messages and the delegation batches they
     * spawned, then the application's kinds ({@see collectHostPurgeKeys()}).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function buildPurgeKeys(AIConversationEvalTrees $trees, AIConversationEvalPurgeSettings $settings): AIConversationEvalPurgeKeys
    {
        $purgeKeys = AIConversationEvalPurgeKeys::newInstance();
        $purgeKeys->setIdsOfKind(AIConversationEvalPurgeKeys::KIND_CONVERSATION, $trees->getConversationIds());
        $conversationIds = $purgeKeys->getConversationIds();
        $purgeKeys->setIdsOfKind(
            AIConversationEvalPurgeKeys::KIND_MESSAGE,
            $this->findIdsWhereColumnIn((string)DBAIConversationMessageModel::getTableName(), 'aiConversationId', $conversationIds, $settings)
        );
        $purgeKeys->setIdsOfKind(
            AIConversationEvalPurgeKeys::KIND_DELEGATION_BATCH,
            $this->findIdsWhereColumnIn((string)DBAIConversationDelegationBatchModel::getTableName(), 'parentConversationId', $conversationIds, $settings)
        );
        $this->collectHostPurgeKeys($purgeKeys, $settings);
        return $purgeKeys;
    }

    /**
     * Adds the ids of the application's own kinds to the keys of a batch (e.g. the rows of its tables that point at
     * the batch's conversations, read with {@see findIdsWhereColumnIn()}). The default: none.
     */
    protected function collectHostPurgeKeys(AIConversationEvalPurgeKeys $purgeKeys, AIConversationEvalPurgeSettings $settings): void
    {
    }

    /**
     * @param int[] $columnValues
     * @return int[] the `id` of every row of `$tableName` whose `$columnName` is one of `$columnValues`
     * @throws \Doctrine\DBAL\Exception
     */
    public function findIdsWhereColumnIn(string $tableName, string $columnName, array $columnValues, AIConversationEvalPurgeSettings $settings): array
    {
        $ids = [];
        $idsWhereColumnInSql = $this->buildIdsWhereColumnInSql($tableName, $columnName);
        foreach (array_chunk($columnValues, max(1, $settings->idsPerStatement)) as $valueChunk) {
            $this->beginStatementLimitedRead();
            try {
                $chunkIds = $this->getConnection()->fetchFirstColumn(
                    $idsWhereColumnInSql,
                    ['columnValues' => $valueChunk],
                    ['columnValues' => ArrayParameterType::INTEGER]
                );
            } finally {
                $this->endStatementLimitedRead();
            }
            $ids = array_merge($ids, array_map('intval', $chunkIds));
        }
        return $ids;
    }

    /** The id read of {@see findIdsWhereColumnIn()}; bound parameter `:columnValues`. */
    public function buildIdsWhereColumnInSql(string $tableName, string $columnName): string
    {
        return 'SELECT ' . $this->quoteName('id') . ' FROM ' . $this->quoteName($tableName) . ' WHERE ' . $this->quoteName($columnName) . ' IN (:columnValues)';
    }

    /**
     * The average row length of every step table — the byte estimate. MariaDB: `information_schema.TABLES` (data
     * length / rows); PostgreSQL: the size of the table's main relation / its estimated row count (`pg_class`), for the
     * tables of `current_schema()` only. Both
     * leave out what the engine stores beside the row (off-page and TOAST data), both are statistics, not counts.
     *
     * @return int[] keyed by table name
     * @throws \Doctrine\DBAL\Exception
     */
    public function findAverageRowBytesByTableName(AIConversationEvalPurgeSteps $steps): array
    {
        $averageRowBytesByTableName = [];
        $this->beginStatementLimitedRead();
        try {
            $tableRows = $this->getConnection()->fetchAllAssociative(
                $this->buildAverageRowBytesSql(),
                ['tableNames' => $steps->getTableNames()],
                ['tableNames' => ArrayParameterType::STRING]
            );
        } finally {
            $this->endStatementLimitedRead();
        }
        foreach ($tableRows as $tableRow) {
            $averageRowBytesByTableName[(string)$tableRow['tableName']] = (int)$tableRow['averageRowBytes'];
        }
        return $averageRowBytesByTableName;
    }

    /** The statistics read of {@see findAverageRowBytesByTableName()} on the engine of the connection; bound parameter `:tableNames`. */
    public function buildAverageRowBytesSql(): string
    {
        $sqlDialect = $this->getSqlDialect();
        $tableNameAlias = $this->quoteName('tableName');
        $averageRowBytesAlias = $this->quoteName('averageRowBytes');
        if ($sqlDialect->name() === SqlDialect::POSTGRES) {
            $relationAlias = $this->quoteName('relation');
            $relationNamespaceAlias = $this->quoteName('relationNamespace');
            return "SELECT {$relationAlias}.relname AS {$tableNameAlias},"
                . " CASE WHEN {$relationAlias}.reltuples > 0 THEN CAST(pg_relation_size({$relationAlias}.oid) / {$relationAlias}.reltuples AS bigint) ELSE 0 END AS {$averageRowBytesAlias}"
                . " FROM pg_catalog.pg_class {$relationAlias} JOIN pg_catalog.pg_namespace {$relationNamespaceAlias} ON {$relationNamespaceAlias}.oid = {$relationAlias}.relnamespace"
                . " WHERE {$relationNamespaceAlias}.nspname = current_schema() AND {$relationAlias}.relkind IN ('r', 'p') AND {$relationAlias}.relname IN (:tableNames)";
        }
        return 'SELECT ' . $this->quoteName('TABLE_NAME') . " AS {$tableNameAlias}, " . $this->quoteName('AVG_ROW_LENGTH') . " AS {$averageRowBytesAlias}"
            . ' FROM ' . $this->quoteName('information_schema') . '.' . $this->quoteName('TABLES')
            . ' WHERE ' . $this->quoteName('TABLE_SCHEMA') . ' = ' . $sqlDialect->currentDatabase() . ' AND ' . $this->quoteName('TABLE_NAME') . ' IN (:tableNames)';
    }

    /**
     * One limited write statement of a step, in a transaction of its own with the polite writer's limits
     * ({@see LOCK_WAIT_TIMEOUT_SECONDS}, {@see STATEMENT_TIMEOUT_SECONDS}). A write that fails — a lock wait or a
     * deadlock ({@see runStepInChunks()} counts it as a skipped chunk), or any other error — rolls the chunk back;
     * the limits are cleared after the commit or the rollback.
     *
     * @param int[] $idChunk
     * @return int the rows written
     * @throws \Doctrine\DBAL\Exception
     */
    protected function executeStepChunk(AIConversationEvalPurgeStep $step, array $idChunk, int $rowLimit): int
    {
        $connection = $this->getConnection();
        $sqlDialect = $this->getSqlDialect();
        $connection->beginTransaction();
        try {
            $sqlDialect->setTransactionTimeouts($connection, static::LOCK_WAIT_TIMEOUT_SECONDS, static::STATEMENT_TIMEOUT_SECONDS);
            $writtenRows = (int)$connection->executeStatement(
                $step->buildWriteSql($rowLimit, $sqlDialect),
                ['ids' => $idChunk],
                ['ids' => ArrayParameterType::INTEGER]
            );
            $connection->commit();
        } catch (Throwable $writeError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $sqlDialect->clearTransactionTimeouts($connection);
            throw $writeError;
        }
        $sqlDialect->clearTransactionTimeouts($connection);
        return $writtenRows;
    }

    /**
     * The dry-run count of one step over one id chunk.
     *
     * @param int[] $idChunk
     * @throws \Doctrine\DBAL\Exception
     */
    protected function countStepRows(AIConversationEvalPurgeStep $step, array $idChunk): int
    {
        $this->beginStatementLimitedRead();
        try {
            return (int)$this->getConnection()->fetchOne(
                $step->buildCountSql($this->getSqlDialect()),
                ['ids' => $idChunk],
                ['ids' => ArrayParameterType::INTEGER]
            );
        } finally {
            $this->endStatementLimitedRead();
        }
    }

    /**
     * The server-load guard shared with the other background rewrites of the message table
     * ({@see AIConversationMessagesService::isDatabaseTooBusyForBackgroundWrites()}). MariaDB only: it reads the
     * server's running threads and history list length there and always answers "not busy" on PostgreSQL.
     *
     * @phpstan-impure reads the live server status
     */
    protected function isDatabaseTooBusy(AIConversationEvalPurgeSettings $settings): bool
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService->isDatabaseTooBusyForBackgroundWrites($settings->maxThreadsRunning, $settings->maxHistoryListLength);
    }

    /**
     * Whether the run's capped budget ({@see AIConversationEvalPurgeSettings::getEffectiveMaxSeconds()}) is used up.
     *
     * @phpstan-impure reads the clock — two calls in a row may answer differently
     */
    public function isTimeBudgetUsedUp(float $startedAtSeconds, AIConversationEvalPurgeSettings $settings): bool
    {
        return ($this->currentSeconds() - $startedAtSeconds) >= $settings->getEffectiveMaxSeconds();
    }

    /** @phpstan-impure the wall clock */
    public function currentSeconds(): float
    {
        return microtime(true);
    }

    /** @phpstan-impure the wall clock, the moment the cutoffs of a run are computed from */
    protected function currentUnixTimestamp(): int
    {
        return time();
    }

    protected function pauseMilliseconds(int $pauseMs): void
    {
        if ($pauseMs > 0) {
            usleep($pauseMs * 1000);
        }
    }

    protected function getConnection(): Connection
    {
        return EntityManagerFactory::getInstance()->getConnection();
    }

    /** The dialect of the connection the purge writes on. */
    protected function getSqlDialect(): SqlDialect
    {
        return EntityManagerFactory::getDialect();
    }

    /** A table, column or alias name quoted by the dialect. */
    public function quoteName(string $name): string
    {
        return $this->getSqlDialect()->quote($name);
    }

    /** `alias.column`, both quoted by the dialect. */
    public function quoteColumn(string $alias, string $columnName): string
    {
        return $this->quoteName($alias) . '.' . $this->quoteName($columnName);
    }

    /** A string literal of SQL: single quotes, an inner quote doubled (the same on both engines). */
    protected function buildStringLiteral(string $text): string
    {
        return "'" . str_replace("'", "''", $text) . "'";
    }

    /** The memory store, the host port that names the memory columns ({@see getColumnsNotPurged()}). */
    protected function getAgentMemoryStoreService(): AgentMemoryStoreService
    {
        /** @var AgentMemoryStoreService $agentMemoryStoreService */
        $agentMemoryStoreService = DDDService::instance()->getService(AgentMemoryStoreService::class);
        return $agentMemoryStoreService;
    }

    /** The goal store, the host port that names the goal columns ({@see getColumnsNotPurged()}). */
    protected function getAgentGoalStoreService(): AgentGoalStoreService
    {
        /** @var AgentGoalStoreService $agentGoalStoreService */
        $agentGoalStoreService = DDDService::instance()->getService(AgentGoalStoreService::class);
        return $agentGoalStoreService;
    }
}
