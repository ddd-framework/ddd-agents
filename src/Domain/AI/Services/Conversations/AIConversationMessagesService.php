<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\TransactionRolledBack;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationMessagePublicationService;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetDelivery;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetOrigin;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetRowChangeSignal;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolCallResult;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentToolResponseSamples;
use DDD\Domain\AI\Entities\Conversations\ControlPanel\AgentToolResponseStats;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationWaitingSignal;
use DDD\Domain\AI\Entities\Conversations\Messages\Maintenance\AIConversationLoadedCapabilitiesRewriteOutcome;
use DDD\Domain\AI\Entities\Conversations\Messages\Maintenance\AIConversationLoadedCapabilitiesRewriteReport;
use DDD\Domain\AI\Entities\Conversations\Messages\Maintenance\AIConversationRequestSnapshotDebugPayloadRemovalReport;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallStoredOutcome;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageIndexLock;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageWritePublication;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageWriteScope;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageWriteUndo;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationWrittenMessagePublication;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallInjectionScreeningVerdict;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageStatusAndTextUndo;
use DDD\Domain\AI\Entities\Conversations\Recall\MessageContextWindow;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageWindow;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilitiesChain;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilitiesRowRewrite;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilitiesStoredRow;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilityRowEncoding;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\MessageHandlers\AIConversationMessageEmbeddingMessage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Infrastructure\Cache\Cache;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonValueKind;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentColdCapabilityUnloading;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedSkillGroups;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;

/**
 * @method AIConversationMessage|null find(string|int|null $entityId, bool $useEntityRegistrCache = true)
 * @method AIConversationMessages findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAIConversationMessage getEntityRepoClassInstance()
 * @method DBAIConversationMessages getEntitySetRepoClassInstance()
 *
 * Raw statements run on MariaDB and on PostgreSQL: every table, column and result alias is written in backticks (the
 * framework rewrites them to the engine's quoting), JSON reads and writes come from the framework dialect
 * ({@see EntityManagerFactory::getDialect()}), and a statement that decides by its affected rows excludes the target
 * state in its WHERE (MariaDB counts changed rows, PostgreSQL matched rows). A JSON write on PostgreSQL passes the
 * document through jsonb: the key order of the stored text is not kept, so nothing compares such a column as text.
 */
class AIConversationMessagesService extends EntitiesService
{
    use AITraceLogTrait;

    /** @var string The row label of a tool row whose provenance names no tool. */
    public const string TOOL_ROW_LABEL_WITHOUT_TOOL_NAME = 'tool';

    public const string DEFAULT_ENTITY_CLASS = AIConversationMessage::class;

    /**
     * FLEET-WIDE tool-response sizes per day × tool for the agent admin control panel — call count +
     * sum/avg/max result characters from the `virtual*` tool-size columns, as the {@see AgentToolResponseStats}
     * set. Throws while those generated columns are not applied on the database yet — the
     * controller maps that to `toolResponseDataAvailable = false`.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findToolResponseStats(DateTime $from, DateTime $to): AgentToolResponseStats
    {
        return $this->getEntityRepoClassInstance()->findToolResponseStats($from, $to);
    }

    /**
     * The drill-down behind ONE {@see self::findToolResponseStats()} row: the LARGEST tool-call responses of one
     * tool over a range (message id, conversation id, when, result/arguments chars), for the
     * per-tool view of an application's debug console command — the payloads one inspects to decide the optimisation.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findLargestToolResponseSamples(string $toolName, DateTime $from, DateTime $to, int $limit = 20): AgentToolResponseSamples
    {
        return $this->getEntityRepoClassInstance()->findLargestToolResponseSamples($toolName, $from, $to, $limit);
    }

    /**
     * Mark the given messages as ingested into the memory store — the curator's harvest marker. A
     * lightweight TARGETED SQL UPDATE (the `markMessagesAsRead` pattern), NOT a full-entity write: the curator is
     * the sole writer of {@see AIConversationMessage::$memoryIngestedAt} and only moves it forward, while the agent
     * loop's full `update()`s leave the (unset) column untouched — so the two never race. No-op on an empty id list.
     *
     * @param int[] $messageIds
     */
    public function markMessagesIngestedIntoMemory(array $messageIds): void
    {
        $messageIds = array_values(array_filter(array_map('intval', $messageIds)));
        if ($messageIds === []) {
            return;
        }
        $tableName = DBAIConversationMessageModel::getTableName();
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            "UPDATE `{$tableName}` SET `memoryIngestedAt` = :ingestedAt WHERE `id` IN (:messageIds)",
            ['ingestedAt' => (new DateTime('now'))->format('Y-m-d H:i:s'), 'messageIds' => $messageIds],
            ['messageIds' => ArrayParameterType::INTEGER]
        );
    }

    // TARGETED single-column message persists: write ONLY the named column(s) via the entity's
    // updatePartialIgnoringRights() so a concurrent path (the async embedding worker's write-back, a delegate settle)
    // can't clobber it — and, unlike a full update(), it does NOT reload/detach the caller's live message.

    /** Persist ONLY the delegate-call's `toolCall` VO + `status` (the settle/timeout verdict). */
    public function persistToolCall(AIConversationMessage $message): void
    {
        if (!isset($message->id) || !isset($message->toolCall)) {
            return;
        }
        $message->updatePartialIgnoringRights(...(isset($message->status) ? ['toolCall', 'status'] : ['toolCall']));
    }

    /**
     * Lock the call's row and read its tool-call status as the ROW holds it (`toolCall.status`), or null when the row /
     * status is absent. The read a writer takes inside its claim-fenced write before re-persisting a delegate call it
     * still holds in memory as RUNNING: the children's workers resolve that status behind the parent's
     * back, and the row stays locked until the write commits, so no child resolves it between this read and the write.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function lockAndFindPersistedToolCallStatus(AIConversationMessage $callMessage): ?string
    {
        if (!isset($callMessage->id)) {
            return null;
        }
        $status = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT ' . EntityManagerFactory::getDialect()->jsonFieldText('`toolCall`', 'status')
            . ' FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `id` = :id FOR UPDATE',
            ['id' => (int)$callMessage->id]
        );
        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * The tool call's status and result as the ROW holds them right now, or null when the row carries no tool call.
     * For a reader whose loaded instance may be older than the row (the eval re-reads a conversation the loop wrote
     * through its own instances).
     *
     * @param int $messageId
     * @return ToolCallStoredOutcome|null
     * @throws \Doctrine\DBAL\Exception
     */
    public function findPersistedToolCallOutcome(int $messageId): ?ToolCallStoredOutcome
    {
        // jsonText() answers SQL NULL for a missing key and for a JSON null, on both engines
        $sqlDialect = EntityManagerFactory::getDialect();
        $storedToolCallOutcome = EntityManagerFactory::getInstance()->getConnection()->fetchAssociative(
            'SELECT ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('status')) . ' AS `status`, '
            . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('result')) . ' AS `result`'
            . ' FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `id` = :id AND `toolCall` IS NOT NULL',
            ['id' => $messageId]
        );
        if (!is_array($storedToolCallOutcome)) {
            return null;
        }
        $storedStatus = $storedToolCallOutcome['status'] ?? null;
        $storedResult = $storedToolCallOutcome['result'] ?? null;
        return ToolCallStoredOutcome::forStatusAndResult(
            is_string($storedStatus) && $storedStatus !== '' ? $storedStatus : null,
            is_string($storedResult) ? $storedResult : null
        );
    }

    /**
     * A fork-join child claims the call row it was started for: `PENDING → RUNNING` plus its lease, in ONE statement
     * (the fork-join claim of the call row). Affected rows = 1 is the owner; 0 means another delivery claimed it, the
     * row was truncated, or the turn already closed it — the child stops without executing.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function claimToolCallExecutionLease(int $messageId, string $lease): bool
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $toolCallWriteSql = $sqlDialect->jsonUpdate('`toolCall`')
            ->set(JsonPath::fromKeys('status'), ':running', JsonValueKind::TEXT)
            ->set(JsonPath::fromKeys('executionLease'), ':lease', JsonValueKind::TEXT)
            ->toSql();
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '` SET `toolCall` = ' . $toolCallWriteSql
            . ' WHERE `id` = :id AND `truncatedAt` IS NULL AND `toolCall` IS NOT NULL'
            . ' AND ' . $sqlDialect->jsonFieldText('`toolCall`', 'status') . ' = :pending',
            [
                'running' => AIConversationMessage::STATUS_RUNNING,
                'lease' => $lease,
                'id' => $messageId,
                'pending' => AIConversationMessage::STATUS_PENDING,
            ]
        );
        return $affectedRows === 1;
    }

    /**
     * Write a delegation child's result slice onto its `delegate` call row ONLY while the row holds no slice of that
     * child yet — one conditional merge of the slice, so a worker that lost its token to a timeout fills the gap without
     * ever replacing the slice the worker that finished the child wrote. Returns whether it wrote.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function mergeDelegationResultSliceUnlessPresent(AIConversation $childConversation): bool
    {
        $delegateMessageId = $childConversation->delegatedFromMessageId ?? null;
        if ($delegateMessageId === null || !isset($childConversation->id)) {
            return false;
        }
        $childKey = (string)$childConversation->id;
        // jsonType() answers SQL NULL only for a key the document does not carry (and for a row without a document)
        $sqlDialect = EntityManagerFactory::getDialect();
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' SET `delegationResults` = ' . $sqlDialect->jsonUpdate('`delegationResults`')->mergePatch(':slice')->toSql()
            . ' WHERE `id` = :id AND ' . $sqlDialect->jsonType('`delegationResults`', JsonPath::fromKeys()->withParameter(':childKey')) . ' IS NULL',
            [
                'slice' => (string)json_encode([$childKey => $childConversation->buildDelegationResultSlice()->toStoredSlice()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id' => (int)$delegateMessageId,
                'childKey' => $childKey,
            ]
        ) === 1;
    }

    /**
     * A queued delegation child was promoted: its `delegate` call moves PENDING → RUNNING — one compare-and-set, so a
     * call a finishing sibling already resolved is never reopened. Returns whether it moved.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function markDelegateCallRunningIfPending(int $messageId): bool
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' SET `toolCall` = ' . $sqlDialect->jsonUpdate('`toolCall`')->set(JsonPath::fromKeys('status'), ':running', JsonValueKind::TEXT)->toSql()
            . ' WHERE `id` = :id AND `toolCall` IS NOT NULL AND ' . $sqlDialect->jsonFieldText('`toolCall`', 'name') . ' = :delegateName'
            . ' AND ' . $sqlDialect->jsonFieldText('`toolCall`', 'status') . ' = :pending',
            [
                'running' => AIConversationMessage::STATUS_RUNNING,
                'id' => $messageId,
                'delegateName' => DelegateTool::NAME,
                'pending' => AIConversationMessage::STATUS_PENDING,
            ]
        ) === 1;
    }

    /**
     * The fork-join child's outcome write: the WHOLE `toolCall` column from the child's loaded VO (result, status,
     * timing, image references — the arguments are intact because the VO was loaded), guarded by the lease and the
     * RUNNING status so a late or redelivered child never overwrites what the turn or another delivery wrote. The
     * one writer of the column while the turn waits (after the fork the column belongs to the child).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function writeToolCallOutcomeIfLeaseHeld(AIConversationMessage $message, string $lease): bool
    {
        if (!isset($message->id) || !isset($message->toolCall)) {
            return false;
        }
        $toolCallJson = json_encode($message->toolCall->mapToRepository(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $sqlDialect = EntityManagerFactory::getDialect();
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' SET `toolCall` = :toolCall'
            . ' WHERE `id` = :id AND `truncatedAt` IS NULL AND `toolCall` IS NOT NULL'
            . ' AND ' . $sqlDialect->jsonFieldText('`toolCall`', 'status') . ' = :running'
            . ' AND ' . $sqlDialect->jsonFieldText('`toolCall`', 'executionLease') . ' = :lease',
            [
                'toolCall' => $toolCallJson,
                'id' => (int)$message->id,
                'running' => AIConversationMessage::STATUS_RUNNING,
                'lease' => $lease,
            ]
        );
        return $affectedRows === 1;
    }

    /**
     * Compare-and-set the delegate call's terminal status IN the row: `toolCall.status` flips to $status only while
     * it is still PENDING / RUNNING, in one statement that touches nothing else of the JSON (arguments, result,
     * the children's merged `delegationResults` stay as they are). Returns whether THIS call won the flip. A
     * full-row re-persist of the message by another worker can still be racing — that writer re-reads the row
     * status first ({@see lockAndFindPersistedToolCallStatus()}). Scoped to `delegate` calls by name — a wrong id can
     * never flip an unrelated in-flight tool call.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function resolveDelegateCallStatusIfUnresolved(int $messageId, string $status): bool
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' SET `toolCall` = ' . $sqlDialect->jsonUpdate('`toolCall`')->set(JsonPath::fromKeys('status'), ':status', JsonValueKind::TEXT)->toSql()
            . ' WHERE `id` = :id AND `toolCall` IS NOT NULL AND ' . $sqlDialect->jsonFieldText('`toolCall`', 'name') . ' = :delegateName'
            . ' AND ' . $sqlDialect->jsonFieldText('`toolCall`', 'status') . ' IN (:pending, :running)',
            [
                'status' => $status,
                'id' => $messageId,
                'delegateName' => DelegateTool::NAME,
                'pending' => AIConversationMessage::STATUS_PENDING,
                'running' => AIConversationMessage::STATUS_RUNNING,
            ]
        );
        return $affectedRows === 1;
    }

    /**
     * The newest sub-agent rows read by {@see findSubAgentRecordsOfConversation()} when the caller names no limit.
     */
    public const int SUB_AGENT_RECORDS_DEFAULT_LIMIT = 50;

    /**
     * Run `$runNumber` of the sub-agent on a `delegate` row was admitted: QUEUED → RUNNING with its
     * start, one guarded write on the row's sub-agent record — only while the record is on that run and the run is
     * still QUEUED, so a redelivered admission or a run that ended first matches zero rows. Returns whether it wrote.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function writeSubAgentRunStarted(int $delegateCallMessageId, int $runNumber, DateTime $startedAt): bool
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $subAgentRecordWriteSql = $sqlDialect->jsonUpdate('`delegationResults`')
            ->set(self::buildSubAgentRunPath($runNumber, 'status'), ':running', JsonValueKind::TEXT)
            ->set(self::buildSubAgentRunPath($runNumber, 'startedAt'), ':startedAt', JsonValueKind::TEXT)
            ->toSql();
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '` SET `delegationResults` = ' . $subAgentRecordWriteSql
            . ' WHERE `id` = :id AND ' . self::buildSubAgentCurrentRunCondition()
            . ' AND ' . $sqlDialect->jsonText('`delegationResults`', self::buildSubAgentRunPath($runNumber, 'status')) . ' = :queued',
            [
                'running' => DelegationSubAgentRun::STATUS_RUNNING,
                'startedAt' => $startedAt->format(DateTime::SIMPLE),
                'id' => $delegateCallMessageId,
                'runNumber' => (string)$runNumber,
                'queued' => DelegationSubAgentRun::STATUS_QUEUED,
            ]
        ) === 1;
    }

    /**
     * A run of the sub-agent on a `delegate` row ended: the child's end (COMPLETED / FAILED), the
     * per-run timeout (TIMED_OUT) or the supersede (CANCELLED) — one writer for the three, since they are the same
     * statement. Writes the end fields `$endedRun` carries (status, finish, verdict, reason, detail, cost) with one
     * guarded write, only while the record is on that run and the run is QUEUED or RUNNING: a terminal run is never
     * overwritten, a redelivered end matches zero rows. A run without a recorded end status writes nothing. Returns
     * whether it wrote.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function writeSubAgentRunEnded(int $delegateCallMessageId, DelegationSubAgentRun $endedRun): bool
    {
        if (!$endedRun->isRecordedRunEnd()) {
            return false;
        }
        $storedEndedRun = $endedRun->mapToRepository();
        $sqlDialect = EntityManagerFactory::getDialect();
        $subAgentRecordWrite = $sqlDialect->jsonUpdate('`delegationResults`');
        $statementParameters = [
            'id' => $delegateCallMessageId,
            'runNumber' => (string)$endedRun->runNumber,
            'unfinishedStatuses' => DelegationSubAgentRun::UNFINISHED_STATUSES,
        ];
        foreach (self::SUB_AGENT_RUN_END_FIELD_NAMES as $endFieldName) {
            if (!array_key_exists($endFieldName, $storedEndedRun)) {
                continue;
            }
            // every end field is bound as its own JSON value, so a string, a number and a null keep their JSON type
            $endFieldParameterName = 'ended' . ucfirst($endFieldName);
            $subAgentRecordWrite->set(self::buildSubAgentRunPath($endedRun->runNumber, $endFieldName), ':' . $endFieldParameterName, JsonValueKind::JSON);
            $statementParameters[$endFieldParameterName] = json_encode($storedEndedRun[$endFieldName], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '` SET `delegationResults` = ' . $subAgentRecordWrite->toSql()
            . ' WHERE `id` = :id AND ' . self::buildSubAgentCurrentRunCondition()
            . ' AND ' . $sqlDialect->jsonText('`delegationResults`', self::buildSubAgentRunPath($endedRun->runNumber, 'status')) . ' IN (:unfinishedStatuses)',
            $statementParameters,
            ['unfinishedStatuses' => ArrayParameterType::STRING]
        ) === 1;
    }

    /**
     * The one notification of run `$runNumber`'s end was written into the parent: stamps
     * `reportedToParentAt` only while the run has ended and carries no stamp yet — the compare-and-set that makes the
     * notification exactly-once (the notifier and the recovery race on it). The record may have moved on to a later
     * run (a continuation opened before the notifier ran). Returns whether THIS call stamped it.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function stampSubAgentRunReportedToParent(int $delegateCallMessageId, int $runNumber, DateTime $reportedAt): bool
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $reportedToParentAtPath = self::buildSubAgentRunPath($runNumber, 'reportedToParentAt');
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' SET `delegationResults` = ' . $sqlDialect->jsonUpdate('`delegationResults`')->set($reportedToParentAtPath, ':reportedAt', JsonValueKind::TEXT)->toSql()
            . ' WHERE `id` = :id'
            . ' AND ' . $sqlDialect->jsonText('`delegationResults`', self::buildSubAgentRunPath($runNumber, 'status')) . ' IN (:endedStatuses)'
            . ' AND ' . $sqlDialect->jsonType('`delegationResults`', $reportedToParentAtPath) . ' IS NULL',
            [
                'reportedAt' => $reportedAt->format(DateTime::SIMPLE),
                'id' => $delegateCallMessageId,
                'endedStatuses' => DelegationSubAgentRun::ENDED_STATUSES,
            ],
            ['endedStatuses' => ArrayParameterType::STRING]
        ) === 1;
    }

    /**
     * Open the continuation run of the sub-agent on a `delegate` row: the record moves from run k-1 to
     * `$continuationRun` (run k, QUEUED, who continued it and the opening message) — one compare-and-set, only while the
     * record is on run k-1, run k-1 ended with a recorded end and run k does not exist yet. Two continuations of one run
     * cannot both win; no run is reset; there is no limit on runs. Returns whether THIS call opened it.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function openSubAgentContinuationRunRecord(int $delegateCallMessageId, DelegationSubAgentRun $continuationRun): bool
    {
        $previousRunNumber = $continuationRun->runNumber - 1;
        if ($previousRunNumber < 1 || !$continuationRun->isQueued()) {
            return false;
        }
        $sqlDialect = EntityManagerFactory::getDialect();
        $continuationRunPath = self::buildSubAgentRunPath($continuationRun->runNumber, null);
        $subAgentRecordWriteSql = $sqlDialect->jsonUpdate('`delegationResults`')
            ->set(JsonPath::fromKeys(DelegationResults::SUB_AGENT_KEY, 'currentRunNumber'), ':continuationRunNumber', JsonValueKind::NUMBER)
            ->set($continuationRunPath, ':continuationRun', JsonValueKind::JSON)
            ->toSql();
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '` SET `delegationResults` = ' . $subAgentRecordWriteSql
            . ' WHERE `id` = :id AND ' . self::buildSubAgentCurrentRunCondition()
            . ' AND ' . $sqlDialect->jsonText('`delegationResults`', self::buildSubAgentRunPath($previousRunNumber, 'status')) . ' IN (:recordedEndStatuses)'
            . ' AND ' . $sqlDialect->jsonType('`delegationResults`', $continuationRunPath) . ' IS NULL',
            [
                'continuationRunNumber' => $continuationRun->runNumber,
                'continuationRun' => json_encode($continuationRun->mapToRepository(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id' => $delegateCallMessageId,
                'runNumber' => (string)$previousRunNumber,
                'recordedEndStatuses' => DelegationSubAgentRun::RECORDED_RUN_END_STATUSES,
            ],
            // the run number is bound as an integer: a string would be stored as a JSON string
            ['continuationRunNumber' => ParameterType::INTEGER, 'recordedEndStatuses' => ArrayParameterType::STRING]
        ) === 1;
    }

    /**
     * The sub-agent records of a conversation, in conversation order: its newest `$limit` `delegate`
     * rows read past rights and the entity registry by one raw select (the children and the notifier write those rows
     * from other processes; a loaded copy is stale), each row read through {@see DelegationResults::buildSubAgentRecords()}
     * — one record for a row written with a sub-agent record, the read-only legacy records for an old multi-task row.
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    public function findSubAgentRecordsOfConversation(AIConversation $parentConversation, int $limit = self::SUB_AGENT_RECORDS_DEFAULT_LIMIT): DelegationSubAgentRecords
    {
        $delegationSubAgentRecords = new DelegationSubAgentRecords();
        if (!isset($parentConversation->id) || $limit < 1) {
            return $delegationSubAgentRecords;
        }
        $delegateCallRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `id`, `toolCall`, `delegationResults` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `virtualToolName` = :delegateName AND `truncatedAt` IS NULL'
            . ' ORDER BY `indexInConversation` DESC LIMIT ' . $limit,
            ['conversationId' => (int)$parentConversation->id, 'delegateName' => DelegateTool::NAME]
        );
        foreach (array_reverse($delegateCallRows) as $delegateCallRow) {
            $delegateCallMessage = $this->buildDelegateCallMessageFromStoredColumns(
                (int)$delegateCallRow['id'],
                is_string($delegateCallRow['toolCall'] ?? null) ? json_decode($delegateCallRow['toolCall'], true, 512, JSON_THROW_ON_ERROR) : null,
                is_string($delegateCallRow['delegationResults'] ?? null) ? json_decode($delegateCallRow['delegationResults'], true, 512, JSON_THROW_ON_ERROR) : null
            );
            foreach ($delegateCallMessage->delegationResults->buildSubAgentRecords($delegateCallMessage)->getElements() as $delegationSubAgentRecord) {
                $delegationSubAgentRecords->add($delegationSubAgentRecord);
            }
        }
        return $delegationSubAgentRecords;
    }

    /**
     * A transient `delegate` call message from its stored columns — what {@see DelegationResults::buildSubAgentRecords()}
     * reads of the row (its id, the call's id, name, arguments and status, the results column). Never persisted.
     *
     * @param array<string, mixed>|null $storedToolCall
     */
    protected function buildDelegateCallMessageFromStoredColumns(int $messageId, ?array $storedToolCall, mixed $storedDelegationResults): AIConversationMessage
    {
        $delegateCallMessage = $this->newMessage();
        $delegateCallMessage->id = $messageId;
        if ($storedToolCall !== null) {
            $delegateToolCall = new ToolCall();
            $delegateToolCall->toolCallId = (string)($storedToolCall['toolCallId'] ?? '');
            $delegateToolCall->name = (string)($storedToolCall['name'] ?? DelegateTool::NAME);
            $delegateToolCall->arguments = is_array($storedToolCall['arguments'] ?? null) ? $storedToolCall['arguments'] : [];
            $delegateToolCall->status = is_string($storedToolCall['status'] ?? null) ? $storedToolCall['status'] : null;
            $delegateCallMessage->toolCall = $delegateToolCall;
        }
        $delegationResults = new DelegationResults();
        $delegationResults->mapFromRepository($storedDelegationResults);
        $delegateCallMessage->delegationResults = $delegationResults;
        return $delegateCallMessage;
    }

    /**
     * The sub-agent record written onto its `delegate` row at the spawn ("— → run 1 QUEUED" / "— →
     * NOT_RUN"): the ONLY time the parent writes the column. Guarded on "the row carries no record yet" — a second spawn
     * write (a retried step) matches zero rows; a legacy map is never replaced. Returns whether it wrote.
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    public function writeSubAgentRecordOfSpawn(int $delegateCallMessageId, DelegationSubAgentRecord $delegationSubAgentRecord): bool
    {
        $delegationResults = new DelegationResults();
        $delegationResults->subAgent = $delegationSubAgentRecord;
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' SET `delegationResults` = :storedRecord'
            . ' WHERE `id` = :id AND (`delegationResults` IS NULL OR '
            . EntityManagerFactory::getDialect()->jsonLength('`delegationResults`', JsonPath::fromKeys()) . ' = 0)',
            [
                'storedRecord' => json_encode($delegationResults->mapToRepository(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id' => $delegateCallMessageId,
            ]
        ) === 1;
    }

    /**
     * The `delegate` row of this conversation that carries sub-agent `$subAgentNumber` (how
     * `message_sub_agent` addresses it), read past rights and the entity registry; null when no row carries it.
     * Truncated rows never match.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findDelegateCallMessageIdOfSubAgentNumber(AIConversation $parentConversation, int $subAgentNumber): ?int
    {
        if (!isset($parentConversation->id)) {
            return null;
        }
        $delegateCallMessageId = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `id` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `virtualToolName` = :delegateName AND `truncatedAt` IS NULL'
            . ' AND ' . self::buildSubAgentNumberSql() . ' = :subAgentNumber'
            . ' ORDER BY `indexInConversation` DESC LIMIT 1',
            ['conversationId' => (int)$parentConversation->id, 'delegateName' => DelegateTool::NAME, 'subAgentNumber' => $subAgentNumber]
        );
        return $delegateCallMessageId === false || $delegateCallMessageId === null ? null : (int)$delegateCallMessageId;
    }

    /**
     * Whether an orchestrator's message to this sub-agent waits for a model call — one indexed read of the
     * stored rows (a timed-out child's run end names it).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function hasOrchestratorMessageWaitingForAModelCall(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        return EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `id` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `messageType` = :signalType AND `signalSource` = :orchestratorMessage'
            . ' AND `status` = :waiting AND `truncatedAt` IS NULL LIMIT 1',
            [
                'conversationId' => (int)$conversation->id,
                'signalType' => AIConversationMessage::MESSAGE_SIGNAL,
                'orchestratorMessage' => AIConversationMessage::SIGNAL_SOURCE_ORCHESTRATOR_MESSAGE,
                'waiting' => AIConversationMessage::STATUS_PENDING,
            ]
        ) !== false;
    }

    /**
     * The whole spend of a conversation in USD as stored — every row's cost and its tool call's execution costs
     * ({@see DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql()}), compacted or truncated rows included
     * (the spend happened). What a sub-agent's run end reads: the loaded window of a continued child that
     * compacted no longer holds the earlier runs' rows, so a sum over it would undercount. A conversation never stored
     * reads its loaded rows.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findTotalCostInUsdOfConversation(AIConversation $conversation): float
    {
        if (!isset($conversation->id)) {
            return isset($conversation->messages) ? $conversation->messages->getTotalCostIncludingDelegationSlicesUsd() : 0.0;
        }
        return round((float)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(' . DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql('message') . '), 0)'
            . ' FROM `' . DBAIConversationMessageModel::getTableName() . '` `message` WHERE `message`.`aiConversationId` = :conversationId',
            ['conversationId' => (int)$conversation->id]
        ), 6);
    }

    /**
     * The orchestrator's message into its sub-agent's child: ONE `ORCHESTRATOR_MESSAGE` signal row,
     * appended above the child's persisted newest row and written WAITING ({@see persistSignalWaitingForRunningTurn()}),
     * never merged into another waiting row — two messages are two rows. A running child takes it at its next model call;
     * its run end is fenced on rows newer than its last look, so a run never ends past the unread message (it yields and
     * continues). The child's inbound activity is stamped. Written by a process that does not drive the child — an
     * INBOUND writer. Returns the row (with its id).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function appendOrchestratorMessageToSubAgent(int $childConversationId, string $orchestratorMessageText): AIConversationMessage
    {
        $childConversation = AIConversation::newInstance();
        $childConversation->id = $childConversationId;
        $childConversation->messages = AIConversationMessages::newInstance();
        $orchestratorMessage = AIConversationMessage::newInstance();
        $childConversation->appendMessage(
            $orchestratorMessage,
            AIConversationMessage::MESSAGE_SIGNAL,
            $orchestratorMessageText,
            AIConversationMessage::ROLE_SYSTEM,
            AIConversationMessage::AUTHOR_AGENT,
            signalSource: AIConversationMessage::SIGNAL_SOURCE_ORCHESTRATOR_MESSAGE
        );
        $this->persistSignalWaitingForRunningTurn($childConversation, $orchestratorMessage, false);
        $this->conversationsService()->recordInboundMessageActivity($childConversation);
        $this->aiTrace('delegation.subAgent.orchestratorMessage.persisted', ['childConversationId' => $childConversationId, 'messageId' => $orchestratorMessage->id ?? null]);
        return $orchestratorMessage;
    }

    /**
     * The number the next sub-agent of this conversation gets: one above the highest number any of its
     * `delegate` rows carries — truncated rows included, so a number is never handed out twice. Read under the parent's
     * claim or in a claimless sync drive (one writer of the parent's rows). A conversation never persisted (no id) reads
     * its loaded rows. Legacy rows carry no number: the first sub-agent of a conversation is #1.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findNextSubAgentNumber(AIConversation $parentConversation): int
    {
        $highestNumber = 0;
        if (isset($parentConversation->messages)) {
            foreach ($parentConversation->messages->buildToolCallHistory()->getDelegateCallRows() as $delegateCallRow) {
                $loadedRecord = isset($delegateCallRow->delegationResults) && isset($delegateCallRow->delegationResults->subAgent)
                    ? $delegateCallRow->delegationResults->subAgent
                    : null;
                $highestNumber = max($highestNumber, (int)($loadedRecord?->number ?? 0));
            }
        }
        if (isset($parentConversation->id)) {
            $highestStoredNumber = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
                'SELECT MAX(' . self::buildSubAgentNumberSql() . ') FROM `' . DBAIConversationMessageModel::getTableName() . '`'
                . ' WHERE `aiConversationId` = :conversationId AND `virtualToolName` = :delegateName',
                ['conversationId' => (int)$parentConversation->id, 'delegateName' => DelegateTool::NAME]
            );
            $highestNumber = max($highestNumber, (int)($highestStoredNumber ?: 0));
        }
        return $highestNumber + 1;
    }

    /**
     * A sub-agent's `delegate` row as it is stored right now (its conversation, call and record), read past rights and
     * the entity registry — the notifier and the timeout read the row a child just wrote. Null when the row is gone.
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    public function findStoredDelegateCallRow(int $delegateCallMessageId): ?AIConversationMessage
    {
        return $this->findStoredDelegateCallRowWithLockClause($delegateCallMessageId, '');
    }

    /**
     * {@see findStoredDelegateCallRow()} with the row locked FOR UPDATE — the first statement of a sub-agent's run-end
     * transaction (the record row is the lock every end of this run takes).
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    public function lockStoredDelegateCallRow(int $delegateCallMessageId): ?AIConversationMessage
    {
        return $this->findStoredDelegateCallRowWithLockClause($delegateCallMessageId, ' FOR UPDATE');
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    protected function findStoredDelegateCallRowWithLockClause(int $delegateCallMessageId, string $lockClause): ?AIConversationMessage
    {
        $delegateCallRow = EntityManagerFactory::getInstance()->getConnection()->fetchAssociative(
            'SELECT `id`, `aiConversationId`, `toolCall`, `delegationResults` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `id` = :id' . $lockClause,
            ['id' => $delegateCallMessageId]
        );
        if ($delegateCallRow === false) {
            return null;
        }
        $delegateCallMessage = $this->buildDelegateCallMessageFromStoredColumns(
            (int)$delegateCallRow['id'],
            is_string($delegateCallRow['toolCall'] ?? null) ? json_decode($delegateCallRow['toolCall'], true, 512, JSON_THROW_ON_ERROR) : null,
            is_string($delegateCallRow['delegationResults'] ?? null) ? json_decode($delegateCallRow['delegationResults'], true, 512, JSON_THROW_ON_ERROR) : null
        );
        $delegateCallMessage->aiConversationId = $delegateCallRow['aiConversationId'] !== null ? (int)$delegateCallRow['aiConversationId'] : null;
        return $delegateCallMessage;
    }

    /**
     * Whether a WAITING signal row above `$aboveIndex` would resume the conversation: every waiting
     * signal except the run end that does not resume the parent — that row waits for the parent's next genuine turn. The
     * release paths' re-check reads this; the hand-over before a model call reads
     * {@see hasSignalWaitingForRunningTurn()} (it takes every waiting row).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function hasSignalWaitingThatResumesTheConversation(AIConversation $conversation, int $aboveIndex = -1): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `indexInConversation` > :aboveIndex AND `messageType` = :signalType'
            . ' AND `signalSource` IN (:signalSources) AND `signalSource` <> :withoutResume AND `status` = :pending AND `truncatedAt` IS NULL',
            [
                'conversationId' => (int)$conversation->id,
                'aboveIndex' => $aboveIndex,
                'signalType' => AIConversationMessage::MESSAGE_SIGNAL,
                'signalSources' => ConversationSignalSources::getValuesPickedUpByRunningTurn(),
                'withoutResume' => AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED_WITHOUT_RESUME,
                'pending' => AIConversationMessage::STATUS_PENDING,
            ],
            ['signalSources' => ArrayParameterType::STRING]
        ) > 0;
    }

    /** @var string[] the fields of a run its end writes ({@see writeSubAgentRunEnded()}) */
    protected const array SUB_AGENT_RUN_END_FIELD_NAMES = ['status', 'finishedAt', 'verdict', 'failureReason', 'failureDetail', 'costInUsd'];

    /** The guard every run writer shares: the row's sub-agent record is on run `:runNumber` (bound as text). */
    protected static function buildSubAgentCurrentRunCondition(): string
    {
        return EntityManagerFactory::getDialect()->jsonText('`delegationResults`', JsonPath::fromKeys(DelegationResults::SUB_AGENT_KEY, 'currentRunNumber'))
            . ' = :runNumber';
    }

    /** The number of the row's sub-agent as an SQL integer; NULL for a row without a sub-agent record. */
    protected static function buildSubAgentNumberSql(): string
    {
        return EntityManagerFactory::getDialect()->jsonInt('`delegationResults`', JsonPath::fromKeys(DelegationResults::SUB_AGENT_KEY, 'number'));
    }

    /**
     * The JSON path of run `$runNumber` of the row's sub-agent record, or of one of its fields
     * (`subAgent` → `runs` → `2` → `status`).
     */
    protected static function buildSubAgentRunPath(int $runNumber, ?string $fieldName): JsonPath
    {
        $subAgentRunPath = JsonPath::fromKeys(DelegationResults::SUB_AGENT_KEY, 'runs', (string)$runNumber);
        return $fieldName === null ? $subAgentRunPath : $subAgentRunPath->withKey($fieldName);
    }

    /**
     * Embed a message's text into its OWN recall-embedding ROW (the redesign that replaced the per-message
     * `messageEmbedding` VECTOR column) — the `bool $async` seam (the canonical message-handler pattern, mirrors
     * {@see AIConversationCompactionService::compactConversationIfDue}): `async:true` → dispatch
     * {@see AIConversationMessageEmbeddingMessage} for the worker and return (no embedding row exists until it runs);
     * `async:false` → generate the vector + UPSERT the row via {@see AIConversationMessageEmbeddingsService}. No-op for
     * a non-recall message (non-TEXT / empty / unpersisted) — it simply gets no embedding row. The async handler + the
     * backfill command both call the sync path; the agent turn calls async.
     *
     * @return bool true when the row was written (sync) or the embedding was dispatched (async); false for a
     *  non-recall message and when no vector came back (no row written, the writer logged it)
     * @throws InternalErrorException
     */
    public function embedMessage(AIConversationMessage $message, bool $async = false): bool
    {
        /** @var AIConversationMessageEmbeddingsService $embeddingsService */
        $embeddingsService = DDDService::instance()->getService(AIConversationMessageEmbeddingsService::class);
        if (!$embeddingsService->isEmbeddable($message)) {
            $this->aiTrace('embedding.skipped', ['messageId' => $message->id ?? null, 'reason' => 'not a recall unit (non-TEXT / empty / unpersisted)']);
            return false;
        }
        if ($async) {
            (new AIConversationMessageEmbeddingMessage((int)$message->id))->dispatch();
            $this->aiTrace('embedding.dispatched', ['messageId' => $message->id]);
            return true;
        }
        if (!$embeddingsService->createEmbeddingForMessage($message)) {
            // no vector came back — no row written, the failure is logged by the writer
            $this->aiTrace('embedding.notGenerated', ['messageId' => $message->id]);
            return false;
        }
        $this->aiTrace('embedding.persisted', ['messageId' => $message->id]);
        return true;
    }

    /**
     * The monotonic consumed marker for supervisor-steer materialization: the highest verdict message id
     * already reflected by a SUPERVISOR_STEERING signal on this conversation. 0 = none yet.
     * {@see \DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages::findLastConsumedSupervisorTurnMessageId}
     */
    public function findLastConsumedSupervisorTurnMessageId(int $conversationId): int
    {
        return $this->getEntitySetRepoClassInstance()->findLastConsumedSupervisorTurnMessageId($conversationId);
    }

    /** Default per-side context budget (tokens) when the caller gives no explicit cap. */
    public const int MESSAGE_CONTEXT_DEFAULT_CAP_TOKENS = 2000;

    /** How often a NEW row is written before an index collision propagates ({@see writeNewRowAtFreeIndex()}). */
    protected const int NEW_ROW_INDEX_COLLISION_ATTEMPTS = 5;

    /** Rough avg chars per content message — turns a char budget into a bounded message LIMIT so the window scan stays cheap. */
    protected const int MESSAGE_CONTEXT_AVG_CHARS_PER_MESSAGE = 150;

    /** Hard ceiling on messages loaded PER SIDE — the window scan can never load more than this regardless of the cap. */
    protected const int MESSAGE_CONTEXT_MAX_MESSAGES_PER_SIDE = 50;

    /**
     * Load the conversational context AROUND one recalled message (the `loadMessageContext` capability): the anchor +
     * the surrounding USER/ASSISTANT dialogue, within three token budgets (anchor / left / right; tokens ≈ chars/4).
     * After a recall hit gives the agent ONE matched line, this gives it the turns around it — and every line carries
     * its messageId so the agent can re-centre and walk the chat.
     *
     * EFFICIENT BY CONSTRUCTION: reduced-column projection (search-hit columns), content-only filter (no tool-call /
     * signal / empty turns), bounded `LIMIT` per side derived from the cap, and — when the anchor alone already fills
     * its cap — the surrounding scan is SKIPPED entirely. Returns null when the message isn't found / not accessible.
     */
    public function loadMessageContext(
        int $messageId,
        int $capContentTokens = self::MESSAGE_CONTEXT_DEFAULT_CAP_TOKENS,
        ?int $capLeftTokens = null,
        ?int $capRightTokens = null
    ): ?MessageContextWindow {
        $repo = $this->getEntitySetRepoClassInstance();
        $anchor = $repo->findContentMessageById($messageId);
        if (!$anchor instanceof AIConversationMessage || !isset($anchor->aiConversationId, $anchor->indexInConversation)) {
            return null;
        }
        // A message of the platform's own run is no message a customer (or the agent working for one) reads back;
        // an administrator reads everything.
        if (AuthService::instance()->getAccount()?->isAdminWithAccessToAllResources() !== true
            && AIConversation::getService()->findCustomerHiddenConversationIds([(int)$anchor->aiConversationId]) !== []) {
            return null;
        }
        $anchorCapTokens = max(1, $capContentTokens);
        $leftCapTokens = max(0, $capLeftTokens ?? $capContentTokens);
        $rightCapTokens = max(0, $capRightTokens ?? $capContentTokens);
        $conversationId = (int)$anchor->aiConversationId;
        $anchorIndex = (int)$anchor->indexInConversation;

        $before = [];
        $after = [];
        // Skip the surrounding scan when the anchor alone already exceeds its cap (nothing would fit anyway).
        if (mb_strlen(MessageContextWindow::buildAnchorText($anchor)) < $anchorCapTokens * 4) {
            if ($leftCapTokens > 0) {
                $before = $repo->findContentMessagesBeforeIndex($conversationId, $anchorIndex, $this->messageContextSideLimit($leftCapTokens))?->getElements() ?? [];
            }
            if ($rightCapTokens > 0) {
                $after = $repo->findContentMessagesAfterIndex($conversationId, $anchorIndex, $this->messageContextSideLimit($rightCapTokens))?->getElements() ?? [];
            }
        }
        return MessageContextWindow::build($anchor, $before, $after, $anchorCapTokens, $leftCapTokens, $rightCapTokens);
    }

    /** Bounded message LIMIT for one side of the context window from its token cap (chars/4 / avg-chars, hard-capped). */
    protected function messageContextSideLimit(int $capTokens): int
    {
        $capChars = $capTokens * 4;
        return min(max((int)ceil($capChars / self::MESSAGE_CONTEXT_AVG_CHARS_PER_MESSAGE), 1), self::MESSAGE_CONTEXT_MAX_MESSAGES_PER_SIDE);
    }

    /**
     * The page size of the backward context window. The agent loop loads a conversation's messages NEWEST-FIRST in
     * windows of this size and loops back toward the START of the conversation, so the freshest turn is always
     * present (the bug this replaces: the framework default top of 50 + ascending order silently loaded the OLDEST
     * 50 messages, dropping the newest user message on any conversation longer than 50 → the loop saw no unanswered
     * user message and never replied). Today the loop runs all the way to the start (the whole conversation is
     * loaded, just paged); once compaction points exist the loop will stop at the newest compaction point INSTEAD of
     * the start — this window is the unit that cap will operate in.
     */
    public const int CONTEXT_MESSAGE_WINDOW = 100;

    /**
     * Runaway backstop for the backward window loop: the most windows it will page before stopping, so a
     * pathologically long conversation can never OOM a worker by loading the whole message graph (with delegation
     * children) into memory. CONTEXT_MESSAGE_WINDOW * this = the hard cap on messages loaded for the agent context.
     * Realistic conversations are a handful of windows; once compaction points exist the loop stops there first and
     * this ceiling becomes moot.
     */
    public const int MAX_CONTEXT_WINDOWS = 50;

    /**
     * Batch size for the memory curator's CHAR-DRIVEN keyset paging ({@see findMemoryCurationWindow}): fetch this many
     * messages, sum their chars, and fetch the next batch ONLY if the char budget is not yet full AND the batch was full
     * (i.e. more rows exist). A per-turn case (one new message) is a single small query; a longer window pages 20 at a
     * time until the char budget is met or the conversation start is reached — never the whole conversation.
     */
    protected const int CURATION_BATCH_SIZE = 20;

    /**
     * Returns Messages for the given Conversation in CHRONOLOGICAL order (oldest → newest), loaded NEWEST-FIRST.
     *
     * - No `$limit` (the agent-loop / inspector / re-drive context load): pages backward in
     *   {@see self::CONTEXT_MESSAGE_WINDOW} windows from the newest message toward the start (bounded by
     *   {@see self::MAX_CONTEXT_WINDOWS}), so the full conversation is loaded with the newest turn guaranteed present.
     * - Explicit `$limit` (the chat frontend's newest-first pagination, or a bounded peek): a single newest-first
     *   page of `$limit` messages.
     *
     * Paging is KEYSET on `indexInConversation` DESC (the per-conversation UNIQUE, append-sequential index): each
     * window is `indexInConversation < <lowest index of the previous window>`, so every page is O(window) and
     * index-friendly — NOT OFFSET (which is O(window+offset) and re-sorts/skips on every page). The result is
     * reversed to chronological before returning, so every caller keeps the ascending-order contract.
     *
     * @param AIConversation $aiConversation
     * @param int|null $offset DEPRECATED / ignored under keyset paging (kept for signature compatibility).
     * @param int|null $limit Single-page size; null = load the whole conversation in keyset windows (capped).
     * @param bool $useEntityRegistrCache
     * @return AIConversationMessages|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAllMessagesForConversationInOrder(
        AIConversation $aiConversation,
        ?int $offset = null,
        ?int $limit = null,
        bool $useEntityRegistrCache = true
    ): ?AIConversationMessages
    {
        if (!isset($aiConversation->id)) {
            if ($this->throwErrors) {
                throw new BadRequestException('Conversation has to be stored before retrieving messages.');
            }
            return null;
        }

        // Explicit limit → a single newest-first page (chat pagination / bounded peek), reversed to chronological.
        // Rebuild through add() — NEVER assign ->elements directly: the direct assignment leaves ObjectSet's
        // internal $elementCount/$elementsByUniqueKey stale, and last()/count() then lie about the set
        // (the stale-anchor root cause on the multi-window path below; same discipline here).
        if ($limit !== null) {
            $page = $this->findNewestMessagePage($aiConversation, $limit, null, $useEntityRegistrCache);
            if ($page instanceof AIConversationMessages) {
                return $this->hydrateLoadedCapabilitiesChain($aiConversation, $this->rebuildChronologicalMessageSet(array_reverse($page->getElements())));
            }
            return $page;
        }

        // No limit → the full context load: page backward in CONTEXT_MESSAGE_WINDOW windows from the newest message
        // toward the start via a KEYSET cursor (indexInConversation < lowest-seen), accumulating newest-first. Every
        // page sets an explicit maxResults, so the framework's default QueryOptions top (50) is never injected.
        // (Future: break the loop at a compaction point instead of the start.) The accumulated newest-first list is
        // reversed to chronological at the end.
        $collectedNewestFirst = [];
        $messagesContainer = null;
        $beforeIndex = null;
        $windowsLoaded = 0;
        do {
            $page = $this->findNewestMessagePage($aiConversation, self::CONTEXT_MESSAGE_WINDOW, $beforeIndex, $useEntityRegistrCache);
            $messagesContainer ??= $page;
            $pageElements = $page?->getElements() ?? [];
            $collectedNewestFirst = array_merge($collectedNewestFirst, $pageElements);
            $windowsLoaded++;
            // Advance the keyset cursor to the lowest index of this (DESC) page = its LAST element.
            $lastElement = end($pageElements);
            $beforeIndex = $lastElement instanceof AIConversationMessage ? $lastElement->indexInConversation : null;
        } while (
            count($pageElements) === self::CONTEXT_MESSAGE_WINDOW
            && $beforeIndex !== null
            && $windowsLoaded < self::MAX_CONTEXT_WINDOWS
        );

        if (!$messagesContainer instanceof AIConversationMessages) {
            return AIConversationMessages::newInstance();
        }
        // Rebuild through add() — the former direct `->elements = array_reverse(...)` assignment kept page 1's
        // $elementCount (100) while the array grew to the full conversation, so ObjectSet::last()/count() stayed
        // pinned at chronological position 99: the context anchor cut everything newer OFF THE WIRE while the
        // model was blamed for "replaying" (proven by the wire snapshots). add() maintains
        // count, unique-key map and lastIndexInConversation in lockstep.
        return $this->hydrateLoadedCapabilitiesChain($aiConversation, $this->rebuildChronologicalMessageSet(array_reverse($collectedNewestFirst)));
    }

    /**
     * Give every loaded row its capability chain state: a row stores either the complete set (a checkpoint)
     * or only its changes; the chain computes what each change row resolves to, and readers reach the set through
     * {@see AIConversationMessage::getLoadedCapabilities()}, which builds it on the first read. When the first
     * set-carrying row of `$messages` is a change row, the stored rows between the newest checkpoint before it and it
     * are read first (one column, bounded by the chain's checkpoint distance).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function hydrateLoadedCapabilitiesChain(AIConversation $aiConversation, ?AIConversationMessages $messages): ?AIConversationMessages
    {
        if ($messages === null || !isset($aiConversation->id)) {
            return $messages;
        }
        $this->bindLoadedMessagesToConversation($aiConversation, $messages);
        return $this->hydrateLoadedCapabilitiesChainOfConversationId((int)$aiConversation->id, $messages);
    }

    /**
     * Every loaded row points at the conversation that LOADED it — the one instance whose `messages` is the bounded tail
     * the caller assigns. Without it a row reaches its conversation through the hydrated relation: a SEPARATE copy per
     * row whose `messages` lazy-loads the framework's oldest 50 rows, so every "newest …" read through the row (the
     * preload's turn stamp, the turn index, the context tokens, persistMessage's run claim) answered from the start of
     * the conversation (the preload stamped turn 48 instead of 56 and was cleaned up at once).
     */
    public function bindLoadedMessagesToConversation(AIConversation $aiConversation, AIConversationMessages $messages): void
    {
        foreach ($messages->getElements() as $message) {
            $message->aiConversation = $aiConversation;
        }
    }

    /**
     * The capability sets of `$messages` resolved against the stored chain of the conversation `$aiConversationId` — the
     * id-only body of {@see hydrateLoadedCapabilitiesChain()} for a load that starts at a message, not at a conversation.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function hydrateLoadedCapabilitiesChainOfConversationId(int $aiConversationId, AIConversationMessages $messages): AIConversationMessages
    {
        $firstChangeRowIndex = null;
        $rowsByIndex = $messages->getElements();
        usort($rowsByIndex, static fn (AIConversationMessage $a, AIConversationMessage $b): int => ($a->indexInConversation ?? PHP_INT_MAX) <=> ($b->indexInConversation ?? PHP_INT_MAX));
        foreach ($rowsByIndex as $message) {
            if (!isset($message->loadedCapabilities)) {
                continue;
            }
            if ($message->loadedCapabilities->isChangeRecord()) {
                $firstChangeRowIndex = $message->indexInConversation ?? null;
            }
            break;
        }
        $canonicalEntriesBeforeFirstRow = $firstChangeRowIndex === null ? null : $this->findLoadedCapabilityCanonicalEntriesBeforeIndexOfConversationId($aiConversationId, (int)$firstChangeRowIndex);
        $messages->buildLoadedCapabilitiesChain()->hydrate($canonicalEntriesBeforeFirstRow);
        return $messages;
    }

    /**
     * The resolved capability set of `$aiConversation` as it stood right BEFORE `$beforeIndex` (the base
     * set of an append-only prefix epoch), from the stored chain; null when no checkpoint exists below it or the
     * conversation is not stored.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findLoadedCapabilitiesBeforeIndex(AIConversation $aiConversation, int $beforeIndex): ?LoadedCapabilities
    {
        if (!isset($aiConversation->id)) {
            return null;
        }
        $canonicalEntries = $this->findLoadedCapabilityCanonicalEntriesBeforeIndexOfConversationId((int)$aiConversation->id, $beforeIndex);
        return $canonicalEntries !== null ? LoadedCapabilities::fromCanonicalEntries($canonicalEntries) : null;
    }

    /**
     * The resolved capability set as it stood right BEFORE `$beforeIndex`, as canonical entries: the newest checkpoint
     * below it, with the stored changes of every row up to `$beforeIndex - 1` applied on arrays (no capability object
     * but the checkpoint's). Null when no checkpoint exists below it. Reads the stored column of the conversation
     * `$aiConversationId` only.
     *
     * @return array<string, array<string, mixed>>|null
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findLoadedCapabilityCanonicalEntriesBeforeIndexOfConversationId(int $aiConversationId, int $beforeIndex): ?array
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $tableName = DBAIConversationMessageModel::getTableName();
        $checkpointIndex = $connection->fetchOne(
            "SELECT MAX(`indexInConversation`) FROM `$tableName` WHERE `aiConversationId` = :aiConversationId"
            . " AND `indexInConversation` < :beforeIndex AND LEFT(`loadedCapabilities`, 1) = '[' AND `truncatedAt` IS NULL",
            ['aiConversationId' => $aiConversationId, 'beforeIndex' => $beforeIndex]
        );
        if ($checkpointIndex === false || $checkpointIndex === null) {
            return null;
        }
        $storedRows = $connection->fetchAllAssociative(
            "SELECT `indexInConversation`, `loadedCapabilities` FROM `$tableName` WHERE `aiConversationId` = :aiConversationId"
            . ' AND `indexInConversation` >= :checkpointIndex AND `indexInConversation` < :beforeIndex AND `truncatedAt` IS NULL ORDER BY `indexInConversation`',
            ['aiConversationId' => $aiConversationId, 'checkpointIndex' => (int)$checkpointIndex, 'beforeIndex' => $beforeIndex]
        );
        $state = null;
        foreach ($storedRows as $storedRow) {
            if ($storedRow['loadedCapabilities'] === null) {
                continue;
            }
            $stored = new LoadedCapabilities();
            $stored->mapFromRepository($storedRow['loadedCapabilities']);
            $storedChanges = $stored->getChangeRecord();
            if ($storedChanges === null) {
                $state = $stored->toCanonicalEntries();
                continue;
            }
            if ($state === null) {
                continue;
            }
            foreach ($storedChanges->getChanges() as $storedChange) {
                $state = $storedChange->applyToCanonicalEntries($state);
            }
        }
        return $state;
    }

    /**
     * A fresh chronological messages set built through add() so EVERY ObjectSet internal (elementCount,
     * elementsByUniqueKey, lastIndexInConversation) matches the elements — the only safe way to materialize an
     * accumulated multi-page load ({@see findAllMessagesForConversationInOrder}).
     *
     * @param AIConversationMessage[] $chronologicalMessages
     */
    protected function rebuildChronologicalMessageSet(array $chronologicalMessages): AIConversationMessages
    {
        $orderedMessages = AIConversationMessages::newInstance();
        foreach ($chronologicalMessages as $chronologicalMessage) {
            $orderedMessages->add($chronologicalMessage);
        }
        return $orderedMessages;
    }

    /**
     * The OWNER's text rows of a conversation in order, with the text columns only — for a containment check over
     * the owner's words (a quoted answer, a statement). The full context load hydrates every request snapshot
     * (megabytes for a long conversation) that such a check never reads.
     */
    public function findOwnerTextMessagesInOrder(AIConversation $aiConversation): ?AIConversationMessages
    {
        if (!isset($aiConversation->id)) {
            return null;
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        // The owner's rows as the statement matcher reads them: a TEXT row the owner wrote (role USER, or
        // authored by the user under another role) and the SIGNAL rows of a widget click / action confirm / form submit
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere(
                "($baseModelAlias.messageType = :textType AND ($baseModelAlias.role = :userRole OR $baseModelAlias.authorType = :userAuthor))"
                . " OR ($baseModelAlias.messageType = :signalType AND $baseModelAlias.signalSource IN (:ownerSignalSources))"
            )
            ->setParameter('aiConversationId', $aiConversation->id)
            ->setParameter('textType', AIConversationMessage::MESSAGE_TEXT)
            ->setParameter('userRole', AIConversationMessage::ROLE_USER)
            ->setParameter('userAuthor', AIConversationMessage::AUTHOR_USER)
            ->setParameter('signalType', AIConversationMessage::MESSAGE_SIGNAL)
            ->setParameter('ownerSignalSources', ConversationSignalSources::getValuesThatCarryAnOwnerStatement())
            ->orderBy("$baseModelAlias.indexInConversation", 'ASC')
            ->setMaxResults(self::CONTEXT_MESSAGE_WINDOW * self::MAX_CONTEXT_WINDOWS);
        return $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::OWNER_TEXT_LOAD_COLUMNS);
    }

    /**
     * The rows the widget view reads, in order, independent of the loaded message window: every
     * ASSISTANT row whose text carries a block marker of a syntax parser ({@see WidgetBlocks::getMessageTextMarkers()}: `widget`
     * for the fence, bare and one-line forms, `::` for the stored leaf form — a superset the parser `WidgetBlocks` narrows) and every owner reaction row (the owner's TEXT
     * rows and the owner-authored SIGNAL rows, {@see AIConversationMessage::isOwnerAuthoredInbound()}). Per conversation
     * on the unique index (aiConversationId, indexInConversation), the NEWEST CONTEXT_MESSAGE_WINDOW × MAX_CONTEXT_WINDOWS
     * rows, text columns only. Null for an unpersisted conversation.
     */
    public function findWidgetAndReactionRows(AIConversation $aiConversation): ?AIConversationMessages
    {
        if (!isset($aiConversation->id)) {
            return null;
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        // one LIKE per marker the syntax parsers declare: a new syntax extends the preselection itself
        $blockMarkerConditions = [];
        foreach (WidgetBlocks::getMessageTextMarkers() as $markerPosition => $messageTextMarker) {
            $blockMarkerConditions[] = "$baseModelAlias.message LIKE :blockMarker$markerPosition";
            $queryBuilder->setParameter('blockMarker' . $markerPosition, '%' . addcslashes($messageTextMarker, '%_\\') . '%');
        }
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere(
                "($baseModelAlias.role = :assistantRole AND (" . implode(' OR ', $blockMarkerConditions) . "))"
                . " OR ($baseModelAlias.messageType = :textType AND ($baseModelAlias.role = :userRole OR $baseModelAlias.authorType = :userAuthor))"
                . " OR ($baseModelAlias.messageType = :signalType AND $baseModelAlias.signalSource IN (:ownerSignalSources))"
            )
            ->setParameter('aiConversationId', $aiConversation->id)
            ->setParameter('assistantRole', AIConversationMessage::ROLE_ASSISTANT)
            ->setParameter('textType', AIConversationMessage::MESSAGE_TEXT)
            ->setParameter('userRole', AIConversationMessage::ROLE_USER)
            ->setParameter('userAuthor', AIConversationMessage::AUTHOR_USER)
            ->setParameter('signalType', AIConversationMessage::MESSAGE_SIGNAL)
            ->setParameter('ownerSignalSources', ConversationSignalSources::getValuesThatAreOwnerAuthored())
            ->orderBy("$baseModelAlias.indexInConversation", 'DESC')
            ->setMaxResults(self::CONTEXT_MESSAGE_WINDOW * self::MAX_CONTEXT_WINDOWS);
        $newestFirstRows = $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::OWNER_TEXT_LOAD_COLUMNS);
        return $this->rebuildChronologicalMessageSet(array_reverse($newestFirstRows?->getElements() ?? []));
    }

    /**
     * The TEXT exchange of a conversation after a message id, in order — the owner's rows and the assistant's text rows,
     * text columns only, at most `$maxRows`, the OLDEST rows past the id first (nothing between the id and the rows returned
     * is skipped). Read by a purposed build that follows the conversation which requested it: the build reads the
     * owner's words itself instead of relying on the chat model to forward them.
     */
    public function findTextExchangeAfterMessageId(AIConversation $aiConversation, int $afterMessageId, int $maxRows): ?AIConversationMessages
    {
        if (!isset($aiConversation->id)) {
            return null;
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere("$baseModelAlias.id > :afterMessageId")
            ->andWhere("$baseModelAlias.messageType = :textType")
            ->andWhere("($baseModelAlias.role IN (:exchangeRoles) OR $baseModelAlias.authorType = :userAuthor)")
            ->setParameter('aiConversationId', $aiConversation->id)
            ->setParameter('afterMessageId', $afterMessageId)
            ->setParameter('textType', AIConversationMessage::MESSAGE_TEXT)
            ->setParameter('exchangeRoles', [AIConversationMessage::ROLE_USER, AIConversationMessage::ROLE_ASSISTANT])
            ->setParameter('userAuthor', AIConversationMessage::AUTHOR_USER)
            ->orderBy("$baseModelAlias.indexInConversation", 'ASC')
            ->setMaxResults(max(1, $maxRows));
        return $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::OWNER_TEXT_LOAD_COLUMNS);
    }

    /**
     * The TEXT exchange of a conversation ORDERED AND PAGED BY ID — the owner's rows and the assistant's text rows, text
     * columns only, at most `$maxRows`, the oldest past the cursor first. A purpose that takes in the owner's words
     * reads every conversation of the space through this: the cursor is
     * an id, and a lower index can be persisted later under a higher id (`persistNewRowIndexSafe()` keeps free indexes),
     * so a read ordered by index could move an id cursor past an unread row. Without a cursor (`$afterMessageId` null)
     * the read is bounded by time instead: rows created at or after `$createdSince` (the run's admission, minus an
     * overlap the ledger key absorbs).
     */
    public function findTextExchangeOrderedById(AIConversation $aiConversation, ?int $afterMessageId, ?DateTime $createdSince, int $maxRows): ?AIConversationMessages
    {
        if (!isset($aiConversation->id) || ($afterMessageId === null && $createdSince === null)) {
            return null;
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere("$baseModelAlias.messageType = :textType")
            ->andWhere("($baseModelAlias.role IN (:exchangeRoles) OR $baseModelAlias.authorType = :userAuthor)")
            ->setParameter('aiConversationId', $aiConversation->id)
            ->setParameter('textType', AIConversationMessage::MESSAGE_TEXT)
            ->setParameter('exchangeRoles', [AIConversationMessage::ROLE_USER, AIConversationMessage::ROLE_ASSISTANT])
            ->setParameter('userAuthor', AIConversationMessage::AUTHOR_USER)
            ->orderBy("$baseModelAlias.id", 'ASC')
            ->setMaxResults(max(1, $maxRows));
        if ($afterMessageId !== null) {
            $queryBuilder->andWhere("$baseModelAlias.id > :afterMessageId")->setParameter('afterMessageId', $afterMessageId);
        } else {
            $queryBuilder->andWhere("$baseModelAlias.created >= :createdSince")->setParameter('createdSince', $createdSince);
        }
        return $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::OWNER_TEXT_LOAD_COLUMNS);
    }

    /**
     * The REQUEST EPISODE of a source chat: the newest TEXT exchange
     * rows with `id <= $upToMessageId` (the trigger message included), optionally above a lower bound (`id > $afterMessageId`,
     * the previous admitted request's cursor) and not older than `$createdSince`, NEWEST FIRST and capped at $maxRows —
     * the owner's rows typed in the main chat BEFORE the request, which the chat model paraphrased into it.
     * Text columns only. Null when the conversation is unpersisted.
     *
     * @throws \Throwable
     */
    public function findTextExchangeEpisodeUpToMessageId(AIConversation $aiConversation, int $upToMessageId, ?int $afterMessageId, ?DateTime $createdSince, int $maxRows): ?AIConversationMessages
    {
        return $this->findTextExchangeEpisode($aiConversation, $upToMessageId, null, $afterMessageId, $createdSince, $maxRows);
    }

    /**
     * The REQUEST EPISODE of ANY conversation of the space (the source-chat read, widened): the newest TEXT exchange rows bounded
     * ABOVE by a message id (`id <= $upToMessageId`, the source chat's trigger) or by a moment (`created < $createdBefore`,
     * the admission for another chat or a call), BELOW by the previous request's cursor and the age bound; newest first,
     * capped. At least one upper bound is required. Text columns only. Null when the conversation is unpersisted.
     *
     * @throws \Throwable
     */
    public function findTextExchangeEpisode(AIConversation $aiConversation, ?int $upToMessageId, ?DateTime $createdBefore, ?int $afterMessageId, ?DateTime $createdSince, int $maxRows): ?AIConversationMessages
    {
        if (!isset($aiConversation->id) || (($upToMessageId === null || $upToMessageId <= 0) && $createdBefore === null)) {
            return null;
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere("$baseModelAlias.messageType = :textType")
            ->andWhere("($baseModelAlias.role IN (:exchangeRoles) OR $baseModelAlias.authorType = :userAuthor)")
            ->setParameter('aiConversationId', $aiConversation->id)
            ->setParameter('textType', AIConversationMessage::MESSAGE_TEXT)
            ->setParameter('exchangeRoles', [AIConversationMessage::ROLE_USER, AIConversationMessage::ROLE_ASSISTANT])
            ->setParameter('userAuthor', AIConversationMessage::AUTHOR_USER)
            ->orderBy("$baseModelAlias.id", 'DESC')
            ->setMaxResults(max(1, $maxRows));
        if ($upToMessageId !== null && $upToMessageId > 0) {
            $queryBuilder->andWhere("$baseModelAlias.id <= :upToMessageId")->setParameter('upToMessageId', $upToMessageId);
        }
        if ($createdBefore !== null) {
            $queryBuilder->andWhere("$baseModelAlias.created < :createdBefore")->setParameter('createdBefore', $createdBefore);
        }
        if ($afterMessageId !== null) {
            $queryBuilder->andWhere("$baseModelAlias.id > :afterMessageId")->setParameter('afterMessageId', $afterMessageId);
        }
        if ($createdSince !== null) {
            $queryBuilder->andWhere("$baseModelAlias.created >= :createdSince")->setParameter('createdSince', $createdSince);
        }
        return $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::OWNER_TEXT_LOAD_COLUMNS);
    }

    /**
     * The TEXT rows named by id, text columns only, in id order — the delivery of the owner-input intake renders the
     * ledger's entries from them (the text stays in the row; the ledger holds a hash). Rows of any conversation.
     *
     * @param int[] $messageIds
     */
    public function findTextMessagesByIds(array $messageIds): ?AIConversationMessages
    {
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if ($messageIds === []) {
            return null;
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$baseModelAlias.id IN (:messageIds)")
            ->andWhere("$baseModelAlias.messageType = :textType")
            ->setParameter('messageIds', $messageIds)
            ->setParameter('textType', AIConversationMessage::MESSAGE_TEXT)
            ->orderBy("$baseModelAlias.id", 'ASC')
            ->setMaxResults(count($messageIds));
        return $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::OWNER_TEXT_LOAD_COLUMNS);
    }

    /**
     * The highest conversation index of an ASSISTANT TEXT row — "has the chat answered past this row?" for an owner row
     * whose conversation is never screened (a call transcript; screening off): the owner-input intake clears an entry
     * once an assistant text row with a higher index exists. Null when the assistant never spoke.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findNewestAssistantTextIndex(AIConversation $aiConversation): ?int
    {
        if (!isset($aiConversation->id)) {
            return null;
        }
        $tableName = DBAIConversationMessageModel::getTableName();
        $newestIndex = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            "SELECT MAX(`indexInConversation`) FROM `{$tableName}` WHERE `aiConversationId` = :aiConversationId AND `messageType` = :textType AND `role` = :assistantRole AND `truncatedAt` IS NULL",
            ['aiConversationId' => (int)$aiConversation->id, 'textType' => AIConversationMessage::MESSAGE_TEXT, 'assistantRole' => AIConversationMessage::ROLE_ASSISTANT]
        );
        return $newestIndex === null || $newestIndex === false ? null : (int)$newestIndex;
    }

    /**
     * One NEWEST-FIRST page of a conversation's messages (`indexInConversation` DESC; soft-deleted/truncated rows are
     * excluded by the repo read-rights filter). KEYSET-paged: pass `$beforeIndex` to fetch the window strictly OLDER
     * than that index (null = the newest page). NOT reversed — the caller decides the final order.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    /**
     * The RAW TAIL only: the verbatim messages AFTER the compaction edge — every message with
     * `indexInConversation > $afterIndex`, chronological. The older messages are already represented by the compaction
     * tree's cover nodes, so the turn-path context load never needs them. One filtered query (the existing
     * UNIQUE(aiConversationId, indexInConversation) serves the `>` range), NOT the backward-windowed full load — so the
     * cost is O(tail), not O(conversation length). The tail is naturally bounded by the compaction threshold (it never
     * grows past it before the next round compacts it). Identical to the no-compaction case, just starting past the
     * edge: the loaded messages flow through buildModelInputUpToAndIncludingMessage exactly the same way.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findRawTailMessagesAfterIndex(
        AIConversation $aiConversation,
        int $afterIndex,
        bool $useEntityRegistrCache = true
    ): ?AIConversationMessages {
        if (!isset($aiConversation->id)) {
            if ($this->throwErrors) {
                throw new BadRequestException('Conversation has to be stored before retrieving messages.');
            }
            return null;
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere("$baseModelAlias.indexInConversation > :afterIndex")
            ->setParameter('aiConversationId', $aiConversation->id)
            ->setParameter('afterIndex', $afterIndex)
            ->orderBy("$baseModelAlias.indexInConversation", 'ASC')
            // EXPLICIT maxResults — WITHOUT it, findMessagesWithColumnProfile's default QueryOptions injects `$top=50`,
            // so this ASC query returns only the OLDEST 50 messages past the cover edge and SILENTLY DROPS the newest.
            // On a compacted conversation whose raw tail exceeds 50 rows (many small messages that still fit under the
            // token threshold — e.g. 78 raw msgs ≈ 46k tok), that means the just-arrived USER message is missing from
            // the derive tail AND getNextIndex() lands on an ALREADY-USED index → persistMessage UNIQUE collision → the turn
            // silently aborts with no reply. The raw tail past the edge is by-definition ALL un-compacted messages
            // (nothing lost — mirrors findAllMessagesForConversationInOrder's "default top never injected" contract and
            // the compaction service's "nothing lost" requirement); the compaction token threshold keeps it well under
            // this bound (100×50 = 5000 rows ≫ any token-bounded tail).
            ->setMaxResults(self::CONTEXT_MESSAGE_WINDOW * self::MAX_CONTEXT_WINDOWS);
        // hydrate the CONTEXT column profile (the heavy columns a path does not read are not hydrated), cache-bypassed.
        return $this->hydrateLoadedCapabilitiesChain($aiConversation, $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::CONTEXT_LOAD_COLUMNS));
    }

    /**
     * The CHAR-DRIVEN, PROJECTED, FILTERED load for the memory curator
     * (the host's account memory curator). Builds the
     * {@see AIConversationMessageWindow} loading ONLY what fits the char budget, ONLY the 8 fields it reads
     * ({@see AIConversationMessage::CURATION_LOAD_COLUMNS} — partial SELECT, no vector/snapshot), and ONLY the relevant rows (the
     * delta/context filter is applied IN SQL), in keyset batches of {@see CURATION_BATCH_SIZE}. Replaces the old
     * whole-conversation load (up to CONTEXT_MESSAGE_WINDOW × MAX_CONTEXT_WINDOWS = 5,000 fat rows per turn).
     *
     *  - DELTA: uningested USER TEXT, oldest-first, paged until $deltaCharBudget chars (or the end). The per-turn case
     *    is one new message = one small query.
     *  - CONTEXT: TEXT USER+ASSISTANT before the oldest delta message, newest-first paged until $contextCharBudget
     *    chars (or the start), rendered chronologically — read-only reference resolution, never extracted from.
     *
     * Empty window (no delta) when nothing is uningested. The Argus extract render applies the exact directional char
     * trim on top, so a final batch slightly over budget is harmless.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findMemoryCurationWindow(
        AIConversation $aiConversation,
        int $deltaCharBudget,
        int $contextCharBudget
    ): AIConversationMessageWindow {
        $window = new AIConversationMessageWindow();
        $window->delta = AIConversationMessages::newInstance();
        if (!isset($aiConversation->id)) {
            return $window;
        }
        $conversationId = (int)$aiConversation->id;

        // Project ONLY the 7 curation fields — find() applies this partial SELECT (no vector/snapshot) on top of our
        // own QueryBuilder. We set NOTHING ELSE on the default options (no filters/orderBy/top), and our QueryBuilder
        // already sets maxResults, so applyQueryOptions leaves WHERE/ORDER/LIMIT untouched. Snapshot/restore so the
        // projection never leaks into other queries this request.
        AIConversationMessages::setDefaultQueryOptionsSnapshot();
        try {
            $select = SelectOptions::fromString(implode(',', AIConversationMessage::CURATION_LOAD_COLUMNS));
            AIConversationMessages::getDefaultQueryOptions()->setSelect($select, false);

            // DELTA — uningested USER TEXT, oldest-first, char-paged.
            $deltaElements = $this->pageMessagesByCharBudget(
                $deltaCharBudget,
                true,
                static function (DoctrineQueryBuilder $queryBuilder, string $alias) use ($conversationId): void {
                    $queryBuilder
                        ->andWhere("$alias.aiConversationId = :curationConversationId")
                        ->andWhere("$alias.memoryIngestedAt IS NULL")
                        ->andWhere("$alias.messageType = :curationTextType")
                        ->andWhere("$alias.role = :curationUserRole")
                        ->setParameter('curationConversationId', $conversationId)
                        ->setParameter('curationTextType', AIConversationMessage::MESSAGE_TEXT)
                        ->setParameter('curationUserRole', AIConversationMessage::ROLE_USER);
                }
            );
            if ($deltaElements === []) {
                return $window; // nothing uningested → nothing to curate
            }
            foreach ($deltaElements as $deltaMessage) {
                $window->delta->add($deltaMessage);
            }
            $oldestDeltaIndex = $deltaElements[0]->indexInConversation ?? 0;

            // CONTEXT — TEXT USER+ASSISTANT before the delta, newest-first char-paged, then reversed to chronological.
            if ($oldestDeltaIndex > 0 && $contextCharBudget > 0) {
                $contextElementsNewestFirst = $this->pageMessagesByCharBudget(
                    $contextCharBudget,
                    false,
                    static function (DoctrineQueryBuilder $queryBuilder, string $alias) use ($conversationId, $oldestDeltaIndex): void {
                        $queryBuilder
                            ->andWhere("$alias.aiConversationId = :curationConversationId")
                            ->andWhere("$alias.indexInConversation < :curationOldestDeltaIndex")
                            ->andWhere("$alias.messageType = :curationTextType")
                            ->andWhere("$alias.role IN (:curationContextRoles)")
                            ->setParameter('curationConversationId', $conversationId)
                            ->setParameter('curationOldestDeltaIndex', $oldestDeltaIndex)
                            ->setParameter('curationTextType', AIConversationMessage::MESSAGE_TEXT)
                            ->setParameter('curationContextRoles', [AIConversationMessage::ROLE_USER, AIConversationMessage::ROLE_ASSISTANT]);
                    }
                );
                if ($contextElementsNewestFirst !== []) {
                    $earlierContext = AIConversationMessages::newInstance();
                    foreach (array_reverse($contextElementsNewestFirst) as $contextMessage) {
                        $earlierContext->add($contextMessage);
                    }
                    $window->earlierContext = $earlierContext;
                }
            }
        } finally {
            AIConversationMessages::restoreDefaultQueryOptionsSnapshot();
        }
        return $window;
    }

    /**
     * Keyset-page a conversation's messages in {@see CURATION_BATCH_SIZE} batches, accumulating until the running char
     * count of `message` exceeds $charBudget OR the conversation end is reached. $ascending picks the direction
     * (oldest-first for the delta, newest-first for the context); the keyset cursor advances on `indexInConversation`.
     * $applyBaseFilter adds the WHERE clauses (with real bound parameters) for the delta or context query; find()
     * layers the {@see AIConversationMessage::CURATION_LOAD_COLUMNS} partial SELECT on top (no fat columns), cache-bypassed so the registry
     * never substitutes a fully-hydrated cached entity for the projection.
     *
     * @param callable(DoctrineQueryBuilder, string): void $applyBaseFilter
     * @return AIConversationMessage[] the loaded messages in page order
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function pageMessagesByCharBudget(int $charBudget, bool $ascending, callable $applyBaseFilter): array
    {
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $direction = $ascending ? 'ASC' : 'DESC';
        $cursorOperator = $ascending ? '>' : '<';

        $collected = [];
        $charsUsed = 0;
        $cursor = null;
        do {
            $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
            $applyBaseFilter($queryBuilder, $baseModelAlias);
            if ($cursor !== null) {
                $queryBuilder
                    ->andWhere("$baseModelAlias.indexInConversation $cursorOperator :curationCursor")
                    ->setParameter('curationCursor', $cursor);
            }
            $queryBuilder
                ->orderBy("$baseModelAlias.indexInConversation", $direction)
                ->setMaxResults(self::CURATION_BATCH_SIZE);
            $pageElements = $entitySetRepoClassInstance->find($queryBuilder, false)->getElements();
            foreach ($pageElements as $message) {
                $collected[] = $message;
                $charsUsed += mb_strlen((string)$message->message);
                $cursor = $message->indexInConversation ?? $cursor;
            }
            $pageWasFull = count($pageElements) === self::CURATION_BATCH_SIZE;
        } while ($pageWasFull && $charsUsed <= $charBudget);
        return $collected;
    }

    /**
     * Load a message set through a partial-SELECT COLUMN PROFILE: snapshot the static default QueryOptions,
     * apply the profile (one of {@see AIConversationMessage}::*_LOAD_COLUMNS) as the SELECT, `find()` with the registry
     * cache BYPASSED, then restore. Cache-bypass is mandatory: {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry::add}
     * writes UNCONDITIONALLY, so a partial-hydrated entity would otherwise linger in the process-static registry. The
     * caller owns the WHERE/ORDER/LIMIT on $queryBuilder. (The `$useEntityRegistrCache` flag threaded by the public
     * loaders is therefore moot on a projected load — projection implies a fresh, registry-bypassed read.)
     *
     * @param string[] $loadColumns
     */
    protected function findMessagesWithColumnProfile(DoctrineQueryBuilder $queryBuilder, array $loadColumns): ?AIConversationMessages
    {
        AIConversationMessages::setDefaultQueryOptionsSnapshot();
        try {
            // setSelect() takes the SelectOptions BY REFERENCE — assign to a variable first (a fromString() return
            // can't be passed by-ref). Mirrors findMemoryCurationWindow.
            $select = SelectOptions::fromString(implode(',', $loadColumns));
            AIConversationMessages::getDefaultQueryOptions()->setSelect($select, false);
            return $this->getEntitySetRepoClassInstance()->find($queryBuilder, false);
        } finally {
            AIConversationMessages::restoreDefaultQueryOptionsSnapshot();
        }
    }

    protected function findNewestMessagePage(
        AIConversation $aiConversation,
        int $limit,
        ?int $beforeIndex,
        bool $useEntityRegistrCache
    ): ?AIConversationMessages {
        return $this->findNewestMessagePageOfConversationId((int)$aiConversation->id, $limit, $beforeIndex);
    }

    /**
     * The integrity validator's message read — an offline inspector read of EVERY row incl. truncated
     * ones, as one projection (index, role, type, the call's name / status / id / requested skills, the stored
     * capability column as raw JSON); never hydrated, never on a turn path.
     *
     * @return array<int, array<string, mixed>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function findIntegrityProjectionRows(AIConversation $conversation): array
    {
        if (!isset($conversation->id)) {
            return [];
        }
        $sqlDialect = EntityManagerFactory::getDialect();
        // the requested skill names are an array: read as its JSON text and decoded by the reader
        return EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            'SELECT `id`, `indexInConversation`, `role`, `messageType`, CASE WHEN `truncatedAt` IS NULL THEN 0 ELSE 1 END AS `truncated`,'
            . ' ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('name')) . ' AS `toolName`,'
            . ' ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('status')) . ' AS `toolStatus`,'
            . ' ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('toolCallId')) . ' AS `toolCallId`,'
            . ' ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('arguments', 'skill_names')) . ' AS `requestedSkillNames`,'
            . ' `loadedCapabilities` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId ORDER BY `indexInConversation`',
            ['conversationId' => (int)$conversation->id]
        );
    }

    /**
     * The conversation's NEWEST `$limit` rows, chronological, whatever the compaction cover says (a historical-record
     * read) — for a question about how the conversation ENDS (the run's report, its final answer,
     * the last wire row). Never {@see findContextTailMessages()} for that: a conversation compacted to completion at
     * finalize has an EMPTY tail above its edge. Bound to the conversation, capability sets resolved.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findNewestMessages(AIConversation $conversation, int $limit): AIConversationMessages
    {
        if (!isset($conversation->id) || $limit <= 0) {
            return AIConversationMessages::newInstance();
        }
        $newestFirstPage = $this->findNewestMessagePageOfConversationId((int)$conversation->id, $limit, null);
        $newestMessages = $this->rebuildChronologicalMessageSet(array_reverse($newestFirstPage?->getElements() ?? []));
        $this->bindLoadedMessagesToConversation($conversation, $newestMessages);
        return $this->hydrateLoadedCapabilitiesChainOfConversationId((int)$conversation->id, $newestMessages);
    }

    /**
     * The `$limit` rows directly BEFORE `$message` (by `indexInConversation`), chronological — one bounded newest-first
     * query on the message's own `aiConversationId`, the CONTEXT column profile, truncated rows excluded, capability sets
     * resolved. Needs no conversation object: the scan starts at the message. The rows point at the conversation
     * `$message` carries when it carries one (never lazy-loaded); else they carry their id only. The entity shorthand is
     * {@see AIConversationMessage::findMessagesBefore()}.
     */
    public function findMessagesBefore(AIConversationMessage $message, int $limit): AIConversationMessages
    {
        $aiConversationId = (int)($message->aiConversationId ?? 0);
        if ($aiConversationId <= 0 || $limit <= 0 || !isset($message->indexInConversation)) {
            return AIConversationMessages::newInstance();
        }
        $newestFirstPage = $this->findNewestMessagePageOfConversationId($aiConversationId, $limit, (int)$message->indexInConversation);
        $messagesBefore = $this->rebuildChronologicalMessageSet(array_reverse($newestFirstPage?->getElements() ?? []));
        if (isset($message->aiConversation)) {
            $this->bindLoadedMessagesToConversation($message->aiConversation, $messagesBefore);
        }
        return $this->hydrateLoadedCapabilitiesChainOfConversationId($aiConversationId, $messagesBefore);
    }

    /**
     * The index of the row that started the turn `$message` belongs to — the newest turn initiator AT or BEFORE it (the
     * owner's own row, or a signal of {@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource::startsATurn()}; the SQL twin of
     * {@see AIConversationMessages::isTurnInitiatorMessage()}). One MAX() query on the message's `aiConversationId`; a row
     * not yet persisted (no index) asks for the conversation's newest initiator. Null when none exists. The entity
     * shorthand is {@see AIConversationMessage::findTurnInitiatorIndex()}.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findTurnInitiatorIndexFor(AIConversationMessage $message): ?int
    {
        $aiConversationId = (int)($message->aiConversationId ?? 0);
        if ($aiConversationId <= 0) {
            return null;
        }
        return $this->findTurnInitiatorIndexOfConversationId($aiConversationId, isset($message->indexInConversation) ? (int)$message->indexInConversation : null);
    }

    /**
     * The conversation's NEWEST turn initiator index — one MAX() query, independent of whatever window is loaded on the
     * entity (the plain-find `messages` relation lazy-loads the OLDEST rows).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findNewestTurnInitiatorIndex(AIConversation $aiConversation): ?int
    {
        return isset($aiConversation->id) ? $this->findTurnInitiatorIndexOfConversationId((int)$aiConversation->id, null) : null;
    }

    /**
     * The rows of ONE turn, from its initiator on, at most `$limit` (a historical-record read): the
     * turn `$requestedTurnMessageId` starts when that message is a turn initiator of this conversation, else the newest
     * turn. Chronological, bound to the conversation. Empty when the conversation has no turn.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findTurnMessages(AIConversation $conversation, ?int $requestedTurnMessageId, int $limit = 300): AIConversationMessages
    {
        if (!isset($conversation->id)) {
            return AIConversationMessages::newInstance();
        }
        $turnInitiatorIndex = null;
        if ($requestedTurnMessageId !== null) {
            $requestedIndex = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
                'SELECT `indexInConversation` FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `id` = :id AND `aiConversationId` = :conversationId AND `truncatedAt` IS NULL',
                ['id' => $requestedTurnMessageId, 'conversationId' => (int)$conversation->id]
            );
            if ($requestedIndex !== false && $requestedIndex !== null
                && $this->findTurnInitiatorIndexOfConversationId((int)$conversation->id, (int)$requestedIndex) === (int)$requestedIndex
            ) {
                $turnInitiatorIndex = (int)$requestedIndex;
            }
        }
        $turnInitiatorIndex ??= $this->findNewestTurnInitiatorIndex($conversation);
        if ($turnInitiatorIndex === null) {
            return AIConversationMessages::newInstance();
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere("$baseModelAlias.indexInConversation >= :turnInitiatorIndex")
            ->setParameter('aiConversationId', (int)$conversation->id)
            ->setParameter('turnInitiatorIndex', $turnInitiatorIndex)
            ->orderBy("$baseModelAlias.indexInConversation", 'ASC')
            ->setMaxResults($limit);
        $turnMessages = $this->rebuildChronologicalMessageSet($this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::CONTEXT_LOAD_COLUMNS)?->getElements() ?? []);
        $this->bindLoadedMessagesToConversation($conversation, $turnMessages);
        return $turnMessages;
    }

    /** @throws \Doctrine\DBAL\Exception */
    protected function findTurnInitiatorIndexOfConversationId(int $aiConversationId, ?int $atOrBeforeIndex): ?int
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $tableName = DBAIConversationMessageModel::getTableName();
        $initiatorIndex = $connection->fetchOne(
            "SELECT MAX(`indexInConversation`) FROM `$tableName` WHERE `aiConversationId` = :aiConversationId AND `truncatedAt` IS NULL"
            . ($atOrBeforeIndex !== null ? ' AND `indexInConversation` <= :atOrBeforeIndex' : '')
            // the orchestrator's task opens a delegation child's turn too (the AUTHOR_AGENT TEXT at index 0) —
            // the SQL twin of AIConversationMessage::isOwnerOrLegacyUserRoleRow()
            . ' AND ((`role` = :userRole AND (`authorType` = :userAuthor OR `authorType` IS NULL'
            . ' OR (`authorType` = :orchestratorAuthor AND `messageType` = :textType AND `indexInConversation` = 0)))'
            . ' OR (`messageType` = :signalType AND `signalSource` IN (:initiatorSignalSources)))',
            array_merge(
                [
                    'aiConversationId' => $aiConversationId,
                    'userRole' => AIConversationMessage::ROLE_USER,
                    'userAuthor' => AIConversationMessage::AUTHOR_USER,
                    'orchestratorAuthor' => AIConversationMessage::AUTHOR_AGENT,
                    'textType' => AIConversationMessage::MESSAGE_TEXT,
                    'signalType' => AIConversationMessage::MESSAGE_SIGNAL,
                    'initiatorSignalSources' => ConversationSignalSources::getValuesThatStartATurn(),
                ],
                $atOrBeforeIndex !== null ? ['atOrBeforeIndex' => $atOrBeforeIndex] : []
            ),
            ['initiatorSignalSources' => ArrayParameterType::STRING]
        );
        return $initiatorIndex === false || $initiatorIndex === null ? null : (int)$initiatorIndex;
    }

    /**
     * The messages a turn, a derive or a context build reads — BOUNDED: only the raw tail above
     * the compaction cover edge when the conversation is compacted (the older rows are represented by the cover
     * nodes), else the full ordered window (an uncompacted conversation stays below the compaction trigger). The cover
     * nodes are loaded first when the conversation carries none — without them the edge is unknown and the load would
     * fall back to the whole history. Never the lazy `messages` relation (the OLDEST 50 rows). Registry-bypassed: the
     * caller wants the persisted state. The one loader of every turn, worker, voice, tool-subprocess and drive path.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findContextTailMessages(AIConversation $conversation): AIConversationMessages
    {
        if (isset($conversation->id) && !isset($conversation->compactionNodes)) {
            $conversation->compactionNodes = $conversation->findCompactionCoverNodes() ?? AIConversationCompactionNodes::newInstance();
        }
        $coveredUpToIndex = $conversation->getCompactionCoveredUpToIndex();
        return ($coveredUpToIndex !== null
            ? $conversation->findRawTailMessagesInOrder($coveredUpToIndex, useEntityRegistrCache: false)
            : $this->findAllMessagesForConversationInOrder($conversation, useEntityRegistrCache: false))
            ?? AIConversationMessages::newInstance();
    }

    /**
     * The id of the conversation's NEWEST owner text message (role USER, author USER, not truncated) — one scalar read,
     * e.g. for the A2A task-initiating message just appended. Null when there is none.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findNewestOwnerTextMessageId(AIConversation $conversation): ?int
    {
        if (!isset($conversation->id)) {
            return null;
        }
        $messageId = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `id` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `role` = :userRole AND `messageType` = :textType AND `authorType` = :userAuthor'
            . ' AND `truncatedAt` IS NULL ORDER BY `indexInConversation` DESC LIMIT 1',
            [
                'conversationId' => (int)$conversation->id,
                'userRole' => AIConversationMessage::ROLE_USER,
                'textType' => AIConversationMessage::MESSAGE_TEXT,
                'userAuthor' => AIConversationMessage::AUTHOR_USER,
            ]
        );
        return $messageId === false || $messageId === null ? null : (int)$messageId;
    }

    /**
     * The conversation's calls of the named tools, the newest `$limit` of them in chronological order (a
     * historical-record read) — by the generated `virtualToolName` column, never the whole history.
     * The entity rules (resolved, not an error, not downgraded, the arguments) stay on {@see AIConversationMessages}.
     *
     * @param string[] $toolNames
     * @throws \Doctrine\DBAL\Exception
     */
    public function findToolCallMessagesByToolNames(AIConversation $conversation, array $toolNames, int $limit = 100): AIConversationMessages
    {
        if (!isset($conversation->id) || $toolNames === []) {
            return AIConversationMessages::newInstance();
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere("$baseModelAlias.virtualToolName IN (:toolNames)")
            ->setParameter('aiConversationId', (int)$conversation->id)
            ->setParameter('toolNames', array_values($toolNames))
            ->orderBy("$baseModelAlias.indexInConversation", 'DESC')
            ->setMaxResults($limit);
        $newestFirstToolCallMessages = $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::CONTEXT_LOAD_COLUMNS);
        return $this->rebuildChronologicalMessageSet(array_reverse($newestFirstToolCallMessages?->getElements() ?? []));
    }

    /**
     * The rows an A2A task projection reads for the task started by the owner text `$taskUserMessageId` — never the
     * whole history and never only its first rows (a historical-record read): the initiating row, the NEXT owner text row
     * (it supersedes the task) when there is one, every `delegate` call of the task (an unresolved one keeps it
     * working, however far back it sits), and the task's newest `$newestRowsLimit` rows (its answer is at its end).
     * Chronological, the task-projection column profile. Empty when the message is not a row of this conversation.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findTaskSliceMessages(AIConversation $conversation, int $taskUserMessageId, int $newestRowsLimit = 200): AIConversationMessages
    {
        if (!isset($conversation->id)) {
            return AIConversationMessages::newInstance();
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $messageTableName = DBAIConversationMessageModel::getTableName();
        $startIndex = $connection->fetchOne(
            "SELECT `indexInConversation` FROM `{$messageTableName}` WHERE `id` = :id AND `aiConversationId` = :conversationId AND `truncatedAt` IS NULL",
            ['id' => $taskUserMessageId, 'conversationId' => (int)$conversation->id]
        );
        if ($startIndex === false || $startIndex === null) {
            return AIConversationMessages::newInstance();
        }
        $startIndex = (int)$startIndex;
        $supersedingIndex = $connection->fetchOne(
            "SELECT MIN(`indexInConversation`) FROM `{$messageTableName}` WHERE `aiConversationId` = :conversationId AND `indexInConversation` > :startIndex"
            . ' AND `role` = :userRole AND `messageType` = :textType AND `authorType` = :userAuthor AND `truncatedAt` IS NULL',
            [
                'conversationId' => (int)$conversation->id, 'startIndex' => $startIndex, 'userRole' => AIConversationMessage::ROLE_USER,
                'textType' => AIConversationMessage::MESSAGE_TEXT, 'userAuthor' => AIConversationMessage::AUTHOR_USER,
            ]
        );
        $endIndex = $supersedingIndex !== false && $supersedingIndex !== null ? (int)$supersedingIndex : null;

        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $boundaryQueryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $boundaryQueryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere("($baseModelAlias.indexInConversation IN (:boundaryIndexes) OR ($baseModelAlias.virtualToolName = :delegateToolName AND $baseModelAlias.indexInConversation > :startIndex"
                . ($endIndex !== null ? " AND $baseModelAlias.indexInConversation < :endIndex" : '') . '))')
            ->setParameter('aiConversationId', (int)$conversation->id)
            ->setParameter('boundaryIndexes', $endIndex !== null ? [$startIndex, $endIndex] : [$startIndex])
            ->setParameter('delegateToolName', DelegateTool::NAME)
            ->setParameter('startIndex', $startIndex);
        $newestQueryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $newestQueryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere("$baseModelAlias.indexInConversation > :startIndex")
            ->setParameter('aiConversationId', (int)$conversation->id)
            ->setParameter('startIndex', $startIndex)
            ->orderBy("$baseModelAlias.indexInConversation", 'DESC')
            ->setMaxResults($newestRowsLimit);
        if ($endIndex !== null) {
            $boundaryQueryBuilder->setParameter('endIndex', $endIndex);
            $newestQueryBuilder->andWhere("$baseModelAlias.indexInConversation < :endIndex")->setParameter('endIndex', $endIndex);
        }
        $rowsByIndex = [];
        foreach ([$boundaryQueryBuilder, $newestQueryBuilder] as $queryBuilder) {
            foreach ($this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::TASK_PROJECTION_LOAD_COLUMNS)?->getElements() ?? [] as $taskRow) {
                $rowsByIndex[(int)$taskRow->indexInConversation] = $taskRow;
            }
        }
        ksort($rowsByIndex);
        $taskSliceMessages = $this->rebuildChronologicalMessageSet(array_values($rowsByIndex));
        $this->bindLoadedMessagesToConversation($conversation, $taskSliceMessages);
        return $taskSliceMessages;
    }

    /**
     * The owner-report rows of the conversation's CURRENT run: OWNER_REPORT text rows above the newest turn initiator,
     * newest first, at most `$limit`, returned in chronological order (a historical-record read).
     * The report check of an owed run reads these instead of the whole history; the content and run-stamp rules stay
     * on {@see AIConversationMessages::getNewestOwnerReportWithContentForRun()}.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findOwnerReportsOfCurrentRun(AIConversation $conversation, int $limit = 5): AIConversationMessages
    {
        if (!isset($conversation->id)) {
            return AIConversationMessages::newInstance();
        }
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->andWhere("$baseModelAlias.signalSource = :ownerReport")
            ->andWhere("$baseModelAlias.messageType = :textType")
            ->andWhere("$baseModelAlias.indexInConversation > :newestInitiatorIndex")
            ->setParameter('aiConversationId', (int)$conversation->id)
            ->setParameter('ownerReport', AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT)
            ->setParameter('textType', AIConversationMessage::MESSAGE_TEXT)
            ->setParameter('newestInitiatorIndex', $this->findNewestTurnInitiatorIndex($conversation) ?? -1)
            ->orderBy("$baseModelAlias.indexInConversation", 'DESC')
            ->setMaxResults($limit);
        $newestFirstReports = $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::CONTEXT_LOAD_COLUMNS);
        return $this->rebuildChronologicalMessageSet(array_reverse($newestFirstReports?->getElements() ?? []));
    }

    /** One newest-first page of a conversation by its id, below `$beforeIndex` when given — the CONTEXT column profile. */
    protected function findNewestMessagePageOfConversationId(int $aiConversationId, int $limit, ?int $beforeIndex): ?AIConversationMessages
    {
        $entitySetRepoClassInstance = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $entitySetRepoClassInstance::getBaseModelAlias();
        $queryBuilder = $entitySetRepoClassInstance::createQueryBuilder();
        $queryBuilder
            ->andWhere("$baseModelAlias.aiConversationId = :aiConversationId")
            ->setParameter('aiConversationId', $aiConversationId)
            ->orderBy("$baseModelAlias.indexInConversation", 'DESC')
            ->setMaxResults($limit);
        if ($beforeIndex !== null) {
            $queryBuilder
                ->andWhere("$baseModelAlias.indexInConversation < :beforeIndex")
                ->setParameter('beforeIndex', $beforeIndex);
        }
        // hydrate the CONTEXT column profile (the heavy columns a path does not read are not hydrated), cache-bypassed.
        return $this->findMessagesWithColumnProfile($queryBuilder, AIConversationMessage::CONTEXT_LOAD_COLUMNS);
    }

    /**
     * Soft-delete (set {@see AIConversationMessage::$truncatedAt}) every message of $aiConversation with
     * `indexInConversation >= $fromIndex`, and recursively truncate any delegation CHILD conversation those messages
     * spawned. NOT a hard delete — the pruned branch is kept; `truncatedAt IS NULL` is filtered in
     * every read (see {@see DBAIConversationMessage::applyReadRightsQuery}) so the agent loop never re-drives the
     * truncated tail. The initial load already excludes truncated rows, so this is naturally idempotent. Loaded copies
     * of the rows are not updated: the caller reloads its messages afterwards.
     *
     * @return int number of messages soft-deleted (this conversation + cascaded children)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function truncateFrom(AIConversation $aiConversation, int $fromIndex): int
    {
        if (!isset($aiConversation->id)) {
            return 0;
        }
        // Under the conversation's message-index lock: a running turn cannot append a delegate call into the range
        // between the mark and the cascade (its children would survive the truncation of their call).
        $messageIndexLock = $this->lockMessageIndex($aiConversation);
        try {
            return $this->truncateFromOfConversationId((int)$aiConversation->id, $fromIndex);
        } finally {
            $this->unlockMessageIndex($messageIndexLock);
        }
    }

    /**
     * The set-based body of {@see truncateFrom()}: the rows from the index on are marked in one
     * statement, THEN the children spawned by exactly the rows this statement marked are found by one id query and
     * truncated whole, recursively through every level of delegation (the graph is a tree, no cycle). The whole history
     * is never loaded. In-memory and registry copies of the rows keep `truncatedAt` unset — the callers reload.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function truncateFromOfConversationId(int $aiConversationId, int $fromIndex): int
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $messageTableName = DBAIConversationMessageModel::getTableName();
        $truncatedAt = (new DateTime())->format('Y-m-d H:i:s');
        $truncatedCount = (int)$connection->executeStatement(
            "UPDATE `{$messageTableName}` SET `truncatedAt` = :truncatedAt"
            . ' WHERE `aiConversationId` = :conversationId AND `indexInConversation` >= :fromIndex AND `truncatedAt` IS NULL',
            ['truncatedAt' => $truncatedAt, 'conversationId' => $aiConversationId, 'fromIndex' => $fromIndex]
        );
        if ($truncatedCount === 0) {
            return 0;
        }
        $spawnedChildIds = array_map('intval', $connection->fetchFirstColumn(
            'SELECT `child`.`id` FROM `' . DBAIConversationModel::getTableName() . '` `child`'
            . " WHERE `child`.`delegatedFromMessageId` IN (SELECT `message`.`id` FROM `{$messageTableName}` `message`"
            . ' WHERE `message`.`aiConversationId` = :conversationId AND `message`.`indexInConversation` >= :fromIndex AND `message`.`truncatedAt` = :truncatedAt)',
            ['conversationId' => $aiConversationId, 'fromIndex' => $fromIndex, 'truncatedAt' => $truncatedAt]
        ));
        foreach ($spawnedChildIds as $spawnedChildId) {
            $truncatedCount += $this->truncateFromOfConversationId($spawnedChildId, 0);
        }
        return $truncatedCount;
    }

    /**
     * Month-to-date spend in USD of ALL SCHEDULE-initiated runs of the given accounts — the consumption side of the
     * SCHEDULE BUDGET POT. Lives HERE and not with the budget service because it sums over
     * {@see AIConversationMessage} rows: a service must not hand-write queries over an aggregate it does not own.
     * The host's account AI costs service
     * calls this for the pot status and keeps the decision.
     *
     * Sums the run ROOTS and their delegation children — without the children a delegation-heavy run would
     * systematically undercount. Budget-bypass roots (admin test traffic) are excluded, mirroring the per-call budget
     * check. Supervisor checkpoints and Argus judgments fall outside the sum.
     *
     * The three filters read the conversation's OWN columns. A delegation child copies `scheduleId`, `accountId` and
     * `budgetBypass` from its parent ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::inheritDelegationScopeFrom()}),
     * so a child's own values already answer all three and the self-join back to the root over
     * `COALESCE(rootConversationId, id)` this used to carry is redundant. That inheritance is the load-bearing
     * precondition, and a delegation-scope inheritance test
     * fails the build if any of the three stops being copied. Checked against production before removing the join:
     * zero children disagree with their root on any of the three (NULL-safe `<=>`).
     *
     * DELIBERATELY raw DBAL, NOT a repo QueryBuilder: this is an ENFORCEMENT read. Going through the repo would apply
     * {@see \DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage::applyReadRightsQuery()} and
     * scope the sum to the authenticated account — for a sub-user that filters away the very rows the cap is measured
     * against, and the pot silently fails OPEN. Do not "fix" this into a repo query.
     *
     * Accepts a LIST of account ids so the tenant-family rollup is a parameter change, not a second query.
     *
     * @param int[] $accountIds
     */
    public function findScheduleSpendMonthToDateUsdForAccounts(array $accountIds, string $currentMonthStart): float
    {
        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
        if ($accountIds === []) {
            return 0.0;
        }
        return (float)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(' . DBAIConversationMessage::buildCostInUSDIncludingToolCallExecutionSql('message') . '), 0)'
            . ' FROM `' . DBAIConversationMessageModel::getTableName() . '` `message`'
            . ' INNER JOIN `' . DBAIConversationModel::getTableName() . '` `conversation`'
            . '  ON `conversation`.`id` = `message`.`aiConversationId`'
            . ' WHERE `conversation`.`scheduleId` IS NOT NULL'
            . ' AND `conversation`.`accountId` IN (:accountIds)'
            . ' AND `conversation`.`budgetBypass` = ' . EntityManagerFactory::getDialect()->boolLiteral(false)
            . ' AND `message`.`created` >= :currentMonthStart',
            ['accountIds' => $accountIds, 'currentMonthStart' => $currentMonthStart],
            ['accountIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
    }

    /**
     * Against the mid-turn inbound collision: take this conversation's named lock for its message
     * indexes, so two writers (the worker persisting its next row, the API injecting an owner click or message) never
     * allocate the same `indexInConversation`. The lock is not exclusive behind ProxySQL + Galera; the strict INSERT of a
     * new row ({@see writeNewRowAtFreeIndex()}) is the guarantee — a collision throws and is retried at the next index.
     * Fail-CLOSED (per-tool-call persist plan, lever E): a lock miss (the 3 s wait ran out, or the acquisition threw — the
     * first statement after the per-tool-call connection renew reconnects lazily) is retried once, then
     * {@see MessageIndexLockUnavailableException} is thrown and the writer writes nothing — an unguarded write is exactly
     * the merge the lock exists to prevent. Ephemeral conversations (no id) take no lock.
     *
     * Re-entrant: a writer whose process already holds the lock — the outermost transaction of a
     * claim-fenced write — gets a hold that took nothing and writes under the outer hold; only the hold that took the lock
     * releases it, after its whole write (the transaction's commit or rollback included) ended. A lock released inside an
     * open transaction let an owner click take it and then wait on this session's uncommitted row lock while this session
     * waited for the index lock again.
     *
     * The writer gives the hold back in a `finally` (the hold replaces the former callback):
     *     $messageIndexLock = $this->lockMessageIndex($conversation);
     *     try { … the write … } finally { $this->unlockMessageIndex($messageIndexLock); }
     *
     * MariaDB holds the lock on the database session until it is released, with or without a transaction. PostgreSQL
     * holds a named lock for ONE transaction: a writer that has none open gets one from the hold — a transaction whose
     * every statement reads the latest commits, so the rows of the writer that held the lock before are visible — and
     * {@see unlockMessageIndex()} commits it; a writer inside a transaction takes the lock in that transaction, where it
     * stays until the transaction ends.
     *
     * @throws MessageIndexLockUnavailableException
     */
    public function lockMessageIndex(AIConversation $conversation): AIConversationMessageIndexLock
    {
        if (!isset($conversation->id)) {
            return AIConversationMessageIndexLock::notTaken();
        }
        $lockName = AIConversationMessageIndexLock::buildLockNameForConversation($conversation);
        if (AIConversationMessageIndexLock::isHeldInThisProcess($lockName)) {
            return AIConversationMessageIndexLock::notTaken();
        }
        $messageIndexLock = null;
        $lastAcquireError = null;
        for ($attempt = 1; $attempt <= 2 && $messageIndexLock === null; $attempt++) {
            try {
                $messageIndexLock = $this->takeMessageIndexLockOnce($lockName);
            } catch (\Throwable $acquireError) {
                // a closed connection (the renew) reconnects on the retry; a persistent error ends below
                $lastAcquireError = $acquireError;
            }
            if ($messageIndexLock === null) {
                $this->aiTrace('messages.indexLock.miss', [
                    'conversationId' => (int)$conversation->id,
                    'attempt' => $attempt,
                    'error' => $lastAcquireError?->getMessage(),
                ]);
            }
        }
        if ($messageIndexLock === null) {
            throw new MessageIndexLockUnavailableException((int)$conversation->id, $lastAcquireError);
        }
        return $messageIndexLock;
    }

    /**
     * One attempt of {@see lockMessageIndex()}: the transaction the lock needs, then the lock. Null when the lock was
     * not free within the wait. A transaction this attempt began is rolled back when the lock was not taken, also when
     * the attempt throws.
     *
     * @throws \Throwable
     */
    protected function takeMessageIndexLockOnce(string $lockName): ?AIConversationMessageIndexLock
    {
        $holdOwnsTransaction = $this->beginTransactionForMessageIndexLock();
        $lockAcquired = false;
        try {
            $lockAcquired = $this->acquireMessageIndexLock($lockName);
        } finally {
            if (!$lockAcquired && $holdOwnsTransaction) {
                $this->rollBackTransactionOfMessageIndexLock();
            }
        }
        return $lockAcquired ? AIConversationMessageIndexLock::taken($lockName, $holdOwnsTransaction) : null;
    }

    /** Whether the engine holds the message-index lock for one transaction (PostgreSQL) instead of on the session until it is released (MariaDB). */
    protected static function isMessageIndexLockHeldForOneTransaction(): bool
    {
        return EntityManagerFactory::getDialect()->capabilities()->isPostgres();
    }

    /**
     * Begins the transaction a lock held for one transaction lives in, when the writer has none open: one whose every
     * statement reads the latest commits. Returns whether this call began a transaction — the hold then ends it
     * ({@see unlockMessageIndex()}). On an engine that holds the lock on the session nothing happens and the connection
     * is not touched.
     *
     * @throws \Throwable
     */
    protected function beginTransactionForMessageIndexLock(): bool
    {
        if (!static::isMessageIndexLockHeldForOneTransaction()) {
            return false;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        if ($connection->isTransactionActive()) {
            return false;
        }
        /** @var AIConversationTransactionsService $aIConversationTransactionsService */
        $aIConversationTransactionsService = DDDService::instance()->getService(AIConversationTransactionsService::class);
        $aIConversationTransactionsService->beginReadCommittedTransaction($connection);
        if (!$connection->isRollbackOnly()) {
            return true;
        }
        // The flag an earlier rollback left behind when it could not reach the server would fail this transaction's
        // commit: it is ended and begun again before the lock is taken in it.
        $connection->rollBack();
        $aIConversationTransactionsService->beginReadCommittedTransaction($connection);
        return true;
    }

    /** Rolls back the transaction an attempt began for a lock it did not get; a rollback that cannot reach the server drops the session. */
    protected function rollBackTransactionOfMessageIndexLock(): void
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        if (!$connection->isTransactionActive()) {
            return;
        }
        try {
            $connection->rollBack();
        } catch (\Throwable) {
            $connection->close();
        }
    }

    /**
     * Ends the transaction a hold began for its lock: what the writer wrote under the hold commits — the same
     * statements commit one by one on an engine whose lock needs no transaction — and a transaction the writer nested
     * in it and rolled back rolls everything back. A transaction the writer has ended itself (a claim-fenced write
     * commits or rolls back the transaction of its hold) is left alone.
     *
     * @throws \Doctrine\DBAL\Exception when the commit fails — the writer's rows are not stored
     */
    protected function endTransactionOfMessageIndexLock(): void
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        if (!$connection->isTransactionActive()) {
            return;
        }
        if ($connection->isRollbackOnly()) {
            $connection->rollBack();
            return;
        }
        $this->commitTransactionOrRollBack($connection);
    }

    /**
     * Give back a hold of {@see lockMessageIndex()}: the hold that took the lock releases it (a failed release is
     * swallowed — the lock expires with the session); a hold that took nothing, or was given back already, does nothing.
     * A hold that began the transaction of its lock ends that transaction first
     * ({@see endTransactionOfMessageIndexLock()}).
     *
     * @throws \Doctrine\DBAL\Exception when the hold's own transaction cannot commit
     */
    public function unlockMessageIndex(AIConversationMessageIndexLock $messageIndexLock): void
    {
        if (!$messageIndexLock->isReleaseOwed()) {
            return;
        }
        $messageIndexLock->markReleased();
        try {
            if ($messageIndexLock->ownsTransaction()) {
                $this->endTransactionOfMessageIndexLock();
            }
        } finally {
            $this->releaseMessageIndexLock((string)$messageIndexLock->lockName);
        }
    }

    /**
     * A new worker job starts without anything a previous job's claim-fenced write left behind (a held lock, an
     * open-write count, queued publications, queued undos — {@see AIConversationMessageIndexLock} and
     * {@see AIConversationMessageWriteScope} hold that process state) — called by the message handlers' job reset. A lock
     * the previous job still held is released on the server too, not only forgotten.
     */
    public static function forgetClaimFencedWriteStateForNewJob(): void
    {
        $heldLockNames = AIConversationMessageIndexLock::forgetLocksHeldInThisProcess();
        if ($heldLockNames !== [] && static::isMessageIndexLockHeldForOneTransaction()) {
            // the locks end with the transaction they were taken in
            try {
                $connection = EntityManagerFactory::getInstance()->getConnection();
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            } catch (\Throwable) {
                // the transaction ends with the session
            }
            $heldLockNames = [];
        }
        foreach ($heldLockNames as $heldLockName) {
            try {
                EntityManagerFactory::getInstance()->getConnection()->fetchOne('SELECT RELEASE_LOCK(:name)', ['name' => $heldLockName]);
            } catch (\Throwable) {
                // the lock expires with the session
            }
        }
        AIConversationMessageWriteScope::forgetOpenWritesOfThisProcess();
    }

    /**
     * Write ONLY the capability column of an existing row — through the change chain, so the row stays a
     * checkpoint or a change row consistent with the rows around it; rows persisted after it are re-encoded. One
     * claim-fenced write: the row and every re-encoded later row commit together, and a worker that
     * lost its claim writes none of them.
     *
     * @param string|null $runToken the claim this process holds on the conversation, null = unfenced (CLI, eval)
     * @throws AgentRunClaimLostException the conversation is claimed by another worker
     */
    public function persistLoadedCapabilitiesUnderRunClaim(AIConversation $conversation, AIConversationMessage $message, ?string $runToken): void
    {
        $writeScope = $this->openClaimFencedWrite($conversation, $runToken);
        try {
            $this->writeLoadedCapabilitiesColumn($conversation, $message);
        } catch (\Throwable $writeError) {
            $this->rollBackClaimFencedWrite($writeScope, $writeError);
        }
        $this->commitClaimFencedWrite($writeScope);
    }

    /** The write of {@see persistLoadedCapabilitiesUnderRunClaim()}, run inside its claim-fenced write. */
    protected function writeLoadedCapabilitiesColumn(AIConversation $conversation, AIConversationMessage $message): void
    {
        if (!isset($conversation->messages)) {
            $message->updatePartialIgnoringRights('loadedCapabilities');
            return;
        }
        $rowEncoding = $conversation->messages->buildLoadedCapabilitiesChain()->buildRowEncoding($message);
        $rowEncoding->applyStoredForm();
        try {
            $message->updatePartialIgnoringRights('loadedCapabilities');
            $this->markLoadedCapabilitiesRowPersisted($rowEncoding);
        } finally {
            $rowEncoding->restoreResolvedForm();
        }
        $this->persistReEncodedRowsAfter($conversation->messages, $message);
    }

    /**
     * Right after a row was written through the capability chain: the row remembers its
     * persisted state, its resolved set forgets the unload reasons the change record took — and their undo is queued
     * for a rollback of the open write. The caller restores the resolved form in its `finally`, written or not.
     */
    public function markLoadedCapabilitiesRowPersisted(LoadedCapabilityRowEncoding $rowEncoding): void
    {
        $unloadReasonsUndo = $rowEncoding->buildUnloadReasonsUndo();
        if ($unloadReasonsUndo !== null) {
            $this->undoOnRollback($unloadReasonsUndo);
        }
        $rowEncoding->markPersisted();
    }

    /**
     * Write ONLY the capability column of the persisted rows after `$message` whose change records were computed
     * against an older state of it — re-encoded by the chain, written in order (a partial write each). Runs inside the
     * claim-fenced write of `$message`.
     */
    public function persistReEncodedRowsAfter(AIConversationMessages $messages, AIConversationMessage $message): void
    {
        foreach ($messages->buildLoadedCapabilitiesChain()->buildReEncodingsOfRowsAfter($message) as $laterRowEncoding) {
            $laterRowEncoding->applyStoredForm();
            try {
                $laterRowEncoding->getMessage()->updatePartialIgnoringRights('loadedCapabilities');
            } finally {
                $laterRowEncoding->restoreResolvedForm();
            }
            $laterRowEncoding->markPersisted();
        }
    }

    /**
     * Open the ONE write primitive of a worker-owned message write: under the conversation's message-index
     * lock (taken first — the order every writer keeps), in one transaction that locks the conversation row
     * (`SELECT runToken … FOR UPDATE`, so a claim steal waits for this write) and refuses when `$runToken` is not the
     * claim the row holds any more. Every row and column the caller writes until the write ends — the capability-chain
     * re-encode of later rows included — commits together or not at all. A nested open (an admission persisting
     * capabilities, a signal acknowledgement persisting its replay row) joins the open lock and transaction. `$runToken`
     * null (no claim held: the CLI/eval drive, an external writer) skips only the claim check. An unpersisted
     * conversation gets an unfenced write: no lock, no transaction.
     *
     * The caller ends the write exactly once (the write scope replaces the former callback):
     *     $writeScope = $this->openClaimFencedWrite($conversation, $runToken);
     *     try { … the write … } catch (\Throwable $writeError) { $this->rollBackClaimFencedWrite($writeScope, $writeError); }
     *     $this->commitClaimFencedWrite($writeScope);
     * An open that fails (the lock, the transaction start, a lost claim) has already rolled back what it began.
     *
     * DBAL 3 opens no savepoint for a nested write: a failure inside one marks the whole outer write rollback-only, so a
     * caught inner failure still fails the outermost commit. When the outermost write rolls back, the in-memory state
     * follows the database again: the conversation's loaded message set is put back as it was when the
     * write began ({@see AIConversationMessages::buildWriteState()}) and every undo a writer queued with
     * {@see undoOnRollback()} runs, newest first.
     *
     * @throws AgentRunClaimLostException when the claim behind $runToken is no longer held — nothing is written
     * @throws MessageIndexLockUnavailableException
     * @throws \Doctrine\DBAL\Exception
     */
    public function openClaimFencedWrite(AIConversation $conversation, ?string $runToken): AIConversationMessageWriteScope
    {
        if (!isset($conversation->id)) {
            return AIConversationMessageWriteScope::unfenced();
        }
        $isOutermostWrite = !AIConversationMessageWriteScope::isAnyWriteOpen();
        if ($isOutermostWrite) {
            AIConversationMessageWriteScope::forgetUndos();
        }
        try {
            $this->queueMessageSetRestore($conversation);
            $messageIndexLock = $this->lockMessageIndex($conversation);
        } catch (\Throwable $lockError) {
            $this->forgetRolledBackClaimFencedWrite($isOutermostWrite);
            throw $lockError;
        }
        $connection = null;
        try {
            $connection = EntityManagerFactory::getInstance()->getConnection();
            // DBAL raises its nesting level BEFORE the driver's START TRANSACTION: a begin that throws (the session died
            // after GET_LOCK, a KILL, a failover) would leave the level at 1 with no server transaction, the connection
            // renewal would skip it for good and every later "outermost" write would run autocommitted, outside the
            // fence. close() resets the level; the write counts as open only once its transaction really began.
            // A hold that began the transaction of its lock (PostgreSQL) has begun this write's transaction with it.
            if (!$messageIndexLock->ownsTransaction()) {
                $connection->beginTransaction();
            }
        } catch (\Throwable $beginError) {
            if ($isOutermostWrite && $connection !== null) {
                $connection->close();
            }
            $this->unlockMessageIndex($messageIndexLock);
            $this->forgetRolledBackClaimFencedWrite($isOutermostWrite);
            throw $beginError;
        }
        $writeScope = AIConversationMessageWriteScope::fenced($isOutermostWrite, $messageIndexLock, $connection);
        try {
            // A rollback-only flag left by an earlier rollback that could not reach the server (DBAL skips its reset
            // then) would fail this write's commit: a write that owns its transaction starts it clean.
            if ($isOutermostWrite && $connection->getTransactionNestingLevel() === 1 && $connection->isRollbackOnly()) {
                $connection->rollBack();
                $connection->beginTransaction();
            }
            if ($runToken !== null) {
                $heldRunToken = $connection->fetchOne(
                    'SELECT `runToken` FROM `' . DBAIConversationModel::getTableName() . '` WHERE `id` = :id FOR UPDATE',
                    ['id' => (int)$conversation->id]
                );
                if ($heldRunToken !== $runToken) {
                    throw new AgentRunClaimLostException('Run-claim lost before a message write — another worker owns this conversation.');
                }
            }
        } catch (\Throwable $claimCheckError) {
            $this->rollBackClaimFencedWrite($writeScope, $claimCheckError);
        }
        return $writeScope;
    }

    /**
     * Commit a write of {@see openClaimFencedWrite()}: the transaction commits, the write counts as ended and its lock
     * hold is given back. The outermost write then drops its undos and runs the deferred publications — AFTER the commit
     * and after the message-index lock is released, so the realtime round trip and an inline embedding never hold up an
     * owner click waiting for the lock. Each publication runs on its own: one that throws neither drops the others nor
     * turns the committed write into a failure. A commit that throws rolls the write back and rethrows
     * ({@see rollBackClaimFencedWrite()}). An unfenced or already ended write does nothing.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function commitClaimFencedWrite(AIConversationMessageWriteScope $writeScope): void
    {
        if (!$writeScope->isFenced || $writeScope->isEnded()) {
            return;
        }
        try {
            $writeScope->connection->commit();
        } catch (\Throwable $commitError) {
            $this->rollBackClaimFencedWrite($writeScope, $commitError);
        }
        $writeScope->markEnded();
        $this->unlockMessageIndex($writeScope->messageIndexLock);
        if (!$writeScope->isOutermostWrite) {
            return;
        }
        AIConversationMessageWriteScope::forgetUndos();
        foreach (AIConversationMessageWriteScope::takePublications() as $publication) {
            try {
                $publication->publish();
            } catch (\Throwable $publicationError) {
                error_log('claim-fenced write: a publication after the commit failed — ' . $publicationError->getMessage());
            }
        }
    }

    /**
     * Roll back a write of {@see openClaimFencedWrite()} whose write failed with `$writeError`, then rethrow it: the
     * transaction rolls back, the write counts as ended and its lock hold is given back; the outermost write drops its
     * publications and runs the queued undos. A rollback that cannot reach the server drops the session — its
     * transaction dies with it; a COMMIT that threw has already zeroed DBAL's nesting counter, so no rollBack() reaches
     * the driver: close() ends the possibly orphaned server-side transaction that would otherwise keep the conversation
     * row locked on this long-lived worker connection (it reconnects on next use). An unfenced or already ended write
     * only rethrows.
     *
     * @throws \Throwable always `$writeError`
     */
    public function rollBackClaimFencedWrite(AIConversationMessageWriteScope $writeScope, \Throwable $writeError): never
    {
        if (!$writeScope->isFenced || $writeScope->isEnded()) {
            throw $writeError;
        }
        $connection = $writeScope->connection;
        if ($connection->isTransactionActive()) {
            try {
                $connection->rollBack();
            } catch (\Throwable) {
                $connection->close();
            }
        } elseif ($writeScope->isOutermostWrite) {
            $connection->close();
        }
        $writeScope->markEnded();
        $this->unlockMessageIndex($writeScope->messageIndexLock);
        $this->forgetRolledBackClaimFencedWrite($writeScope->isOutermostWrite);
        throw $writeError;
    }

    /** After the outermost write rolled back (or failed to open): its publications are dropped and its undos run. */
    protected function forgetRolledBackClaimFencedWrite(bool $isOutermostWrite): void
    {
        if (!$isOutermostWrite) {
            return;
        }
        AIConversationMessageWriteScope::forgetPublications();
        $this->runUndosOnRollback();
    }

    /** Whether this process is inside a claim-fenced write right now — a database error there must fail the write. */
    public static function isClaimFencedWriteOpen(): bool
    {
        return AIConversationMessageWriteScope::isAnyWriteOpen();
    }

    /**
     * Undo an in-memory change of the open claim-fenced write if that write rolls back — a flipped signal
     * status, a capability set the write changed. Outside a fenced write there is nothing to roll back: dropped.
     */
    public function undoOnRollback(AIConversationMessageWriteUndo $undo): void
    {
        AIConversationMessageWriteScope::queueUndoOfOpenWrite($undo);
    }

    /** Queue the restore of the conversation's loaded message set once per open write (a nested write joins it). */
    protected function queueMessageSetRestore(AIConversation $conversation): void
    {
        if (!isset($conversation->messages)) {
            return;
        }
        AIConversationMessageWriteScope::queueMessageSetRestore($conversation->messages);
    }

    /** Run the queued undos newest first, each on its own — one that throws never keeps the others from running. */
    protected function runUndosOnRollback(): void
    {
        foreach (AIConversationMessageWriteScope::takeUndosNewestFirst() as $undo) {
            try {
                $undo->undo();
            } catch (\Throwable $undoError) {
                error_log('claim-fenced write: an undo after the rollback failed — ' . $undoError->getMessage());
            }
        }
    }

    /**
     * Write the injection-screening verdict onto a call row only while the ROW still says `pending`:
     * the row is locked, re-read, the verdict changes the row's CURRENT call (never the job's older copy) and that call
     * is written. A row already screened — a second delivery of the job — or no longer a call is left alone. The written
     * call is mirrored onto `$callMessage` after the commit. Returns whether the verdict was written.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function persistToolCallVerdictWhileScreeningPending(AIConversationMessage $callMessage, ToolCallInjectionScreeningVerdict $injectionScreeningVerdict): bool
    {
        if (!isset($callMessage->id)) {
            return false;
        }
        // the transaction of Connection::transactional(), spelled out: the row-locked write is a named method
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $connection->beginTransaction();
        $verdictWriteSucceeded = false;
        try {
            $writtenToolCall = $this->writeToolCallVerdictWhileScreeningPending($connection, $callMessage, $injectionScreeningVerdict);
            $verdictWriteSucceeded = true;
        } finally {
            if (!$verdictWriteSucceeded) {
                $connection->rollBack();
            }
        }
        $this->commitTransactionOrRollBack($connection);
        // mirrored only once the verdict is committed
        if ($writtenToolCall === null) {
            return false;
        }
        $callMessage->toolCall = $writtenToolCall;
        return true;
    }

    /**
     * The row-locked body of {@see persistToolCallVerdictWhileScreeningPending()}, run inside its transaction: the call
     * the row holds with the verdict applied and written, or null when the row is no longer pending or no call.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function writeToolCallVerdictWhileScreeningPending(Connection $connection, AIConversationMessage $callMessage, ToolCallInjectionScreeningVerdict $injectionScreeningVerdict): ?ToolCall
    {
        $rowScreening = $connection->fetchOne(
            'SELECT ' . EntityManagerFactory::getDialect()->jsonText('`toolCall`', JsonPath::fromKeys('injectionScreening'))
            . ' FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `id` = :id FOR UPDATE',
            ['id' => (int)$callMessage->id]
        );
        if ($rowScreening !== ToolCall::INJECTION_SCREENING_PENDING) {
            return null;
        }
        $currentCallMessage = $this->find((int)$callMessage->id, false);
        $currentToolCall = $currentCallMessage !== null && isset($currentCallMessage->toolCall) ? $currentCallMessage->toolCall : null;
        if ($currentToolCall === null) {
            return null;
        }
        $injectionScreeningVerdict->applyTo($currentToolCall);
        $currentCallMessage->updatePartialIgnoringRights('toolCall');
        return $currentToolCall;
    }

    /**
     * The commit of {@see Connection::transactional()}: a commit that throws is rolled back, except after a driver error
     * that already ended the transaction on the server (a rolled-back transaction, a unique or foreign-key violation, a
     * deadlock).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function commitTransactionOrRollBack(Connection $connection): void
    {
        $shouldRollBack = true;
        try {
            $connection->commit();
            $shouldRollBack = false;
        } catch (DBALDriverException $driverException) {
            $convertedException = $connection->convertException($driverException);
            $shouldRollBack = !(
                $convertedException instanceof TransactionRolledBack
                || $convertedException instanceof UniqueConstraintViolationException
                || $convertedException instanceof ForeignKeyConstraintViolationException
                || $convertedException instanceof DeadlockException
            );
            throw $driverException;
        } finally {
            if ($shouldRollBack) {
                $connection->rollBack();
            }
        }
    }

    /**
     * Run a publication of a written row (its embedding dispatch, its realtime tick) once the row is visible to other
     * processes: right away outside a claim-fenced write, after the outermost commit inside one — the
     * embedding worker drops a job whose row it cannot read yet.
     */
    public function publishAfterCommit(AIConversationMessageWritePublication $publication): void
    {
        if (AIConversationMessageWriteScope::queuePublicationOfOpenWrite($publication)) {
            return;
        }
        $publication->publish();
    }

    /**
     * Write a widget a TOOL hands to the chat (the one way a tool writes into the customer's
     * transcript; formerly the tool's provenance was forwarded here through the agent service). The stored block
     * names the tool as its origin (`emittedBy: tool:<toolName>`); the row's label is the tool name. The
     * delivery is decided here, never by the caller ({@see appendToolRow()}). A delivery without a row lets the caller
     * dictate {@see ConversationWidget::buildWidgetBlock()} (no `emittedBy`) in its result, speak it, or write nothing.
     *
     * @throws AgentRunClaimLostException the run claim was lost — nothing is written
     * @throws MessageIndexLockUnavailableException
     */
    public function appendWidget(AgentToolCallProvenance $agentToolCallProvenance, ConversationWidget $conversationWidget): ConversationWidgetDelivery
    {
        $toolRowLabel = $agentToolCallProvenance->toolName ?? self::TOOL_ROW_LABEL_WITHOUT_TOOL_NAME;
        return $this->appendToolRow(
            $agentToolCallProvenance,
            $conversationWidget->buildWidgetBlockEmittedBy(ConversationWidgetOrigin::tool($toolRowLabel)),
            $toolRowLabel
        );
    }

    /**
     * Write a tool's own words (a short notice line, no widget) into the chat — the same delivery as
     * {@see appendWidget()}; the row carries no block and so no `emittedBy`, its label is the tool name.
     *
     * @throws AgentRunClaimLostException the run claim was lost — nothing is written
     * @throws MessageIndexLockUnavailableException
     */
    public function appendToolText(AgentToolCallProvenance $agentToolCallProvenance, string $toolText): ConversationWidgetDelivery
    {
        return $this->appendToolRow($agentToolCallProvenance, $toolText, $agentToolCallProvenance->toolName ?? self::TOOL_ROW_LABEL_WITHOUT_TOOL_NAME);
    }

    /**
     * The delivery of a tool's row, decided in this order: an eval-harness call writes nothing (NO_CHAT — a harness
     * read never writes the judged transcript); a call in a delegation child writes no row (DELEGATION_CHILD — nobody
     * reads a child's transcript, nothing is copied into the root; the provenance's own predicate,
     * {@see AgentToolCallProvenance::isInDelegationChild()}); no conversation to write into is NO_CHAT (the HTTP-MCP
     * path); the owner on the phone line gets no row (PHONE_LINE — a call shows no screen); a blank row
     * is NO_CHAT. Otherwise the trimmed row is WRITTEN through {@see writeWidgetRow()}.
     *
     * @throws AgentRunClaimLostException
     * @throws MessageIndexLockUnavailableException
     */
    protected function appendToolRow(AgentToolCallProvenance $agentToolCallProvenance, string $toolRowText, string $toolRowLabel): ConversationWidgetDelivery
    {
        if ($agentToolCallProvenance->isEvalHarnessCall) {
            return ConversationWidgetDelivery::noChat();
        }
        if ($agentToolCallProvenance->isInDelegationChild()) {
            return ConversationWidgetDelivery::delegationChild();
        }
        $conversation = $agentToolCallProvenance->getConversationToWriteInto();
        if ($conversation === null) {
            return ConversationWidgetDelivery::noChat();
        }
        if ($conversation->isOwnerOnThePhoneLine()) {
            return ConversationWidgetDelivery::phoneLine();
        }
        $trimmedToolRowText = trim($toolRowText);
        if ($trimmedToolRowText === '') {
            return ConversationWidgetDelivery::noChat();
        }
        return ConversationWidgetDelivery::written($this->writeWidgetRow($conversation, $toolRowLabel, $trimmedToolRowText));
    }

    /**
     * Write a widget the PLATFORM puts into the chat on its own — no tool call asked for it
     * (a question a purpose asks at its start, a form after a declined proposal). The block names the origin
     * `emittedBy: platform:<reason>`, the reason a constant of the writing domain; the row's label is the same value
     * as well. The delivery is decided here, never by the caller ({@see appendWidgetOfOrigin()}).
     *
     * @throws AgentRunClaimLostException the run claim was lost — nothing is written
     * @throws MessageIndexLockUnavailableException
     */
    public function appendPlatformWidget(AIConversation $conversation, ConversationWidget $conversationWidget, string $platformReason): ConversationWidgetDelivery
    {
        return $this->appendWidgetOfOrigin($conversation, $conversationWidget, ConversationWidgetOrigin::platform($platformReason));
    }

    /**
     * Write the widget of a META tool's recorded call that the platform renders at the turn's end — the `askOwner`
     * question under the final text: the meta tool has no tool-call provenance at that moment,
     * so the platform writes for it. The block names `emittedBy: tool:<metaToolName>`, the row keeps the tool name as
     * its label. The delivery is decided here ({@see appendWidgetOfOrigin()}).
     *
     * @throws AgentRunClaimLostException the run claim was lost — nothing is written
     * @throws MessageIndexLockUnavailableException
     */
    public function appendMetaToolWidget(AIConversation $conversation, ConversationWidget $conversationWidget, string $metaToolName): ConversationWidgetDelivery
    {
        return $this->appendWidgetOfOrigin($conversation, $conversationWidget, ConversationWidgetOrigin::tool($metaToolName));
    }

    /**
     * The one writer of a widget the platform hands to the chat. It decides the delivery as the tool writer does
     * ({@see appendToolRow()}): a delegation child
     * writes no row (DELEGATION_CHILD — nobody reads a child's transcript), the owner on the phone line gets none either
     * (PHONE_LINE — the call shows no screen), a blank row is NO_CHAT; otherwise the row is WRITTEN. A
     * failure of the write reaches the caller: the end-of-turn callers rethrow a lost run claim and swallow the rest.
     *
     * @throws AgentRunClaimLostException
     * @throws MessageIndexLockUnavailableException
     */
    protected function appendWidgetOfOrigin(AIConversation $conversation, ConversationWidget $conversationWidget, ConversationWidgetOrigin $conversationWidgetOrigin): ConversationWidgetDelivery
    {
        if ($conversation->isDelegationChild()) {
            return ConversationWidgetDelivery::delegationChild();
        }
        if ($conversation->isOwnerOnThePhoneLine()) {
            return ConversationWidgetDelivery::phoneLine();
        }
        $widgetRowText = trim($conversationWidget->buildWidgetRowEmittedBy($conversationWidgetOrigin));
        if ($widgetRowText === '') {
            return ConversationWidgetDelivery::noChat();
        }
        return ConversationWidgetDelivery::written($this->writeWidgetRow($conversation, (string)$conversationWidgetOrigin->toRowLabel(), $widgetRowText));
    }

    /**
     * The widget row itself: a TOOL_MESSAGE (role ASSISTANT, author TOOL — model-invisible, never owed work) appended to
     * the conversation's loaded set, persisted under this process's run claim at a free index, and announced by the
     * realtime tick once committed ({@see ConversationWidgetRowChangeSignal}). The row of every writer: the tool's
     * ({@see appendToolRow()}), the platform's and the meta tool's ({@see appendWidgetOfOrigin()}).
     *
     * @throws AgentRunClaimLostException
     * @throws MessageIndexLockUnavailableException
     */
    protected function writeWidgetRow(AIConversation $conversation, string $rowLabel, string $widgetRowText): AIConversationMessage
    {
        $widgetRow = AIConversationMessage::newInstance();
        $conversation->appendMessage(
            $widgetRow,
            AIConversationMessage::MESSAGE_TOOL_MESSAGE,
            $widgetRowText,
            AIConversationMessage::ROLE_ASSISTANT,
            AIConversationMessage::AUTHOR_TOOL,
            signalSource: $rowLabel
        );
        // the `$from` bindings of a ComposedView block are resolved right before the row is written
        $this->composedViewBindingResolutionService()->resolveBindingsOfRowBeforePersist($widgetRow);
        $this->persistNewRowUnderRunClaim($conversation, $widgetRow);
        $this->publishAfterCommit(ConversationWidgetRowChangeSignal::forConversation($conversation, $this->realtimeSignalService()));
        return $widgetRow;
    }

    /**
     * Persist a fully-built message of a conversation under the run that writes it (the body of the
     * loop's former `persistMessage`): ONE claim-fenced write when the conversation is persisted (the index lock, the
     * conversation row locked with the claim check, the row, the delegate-status merge, the capability-chain re-encode of
     * later rows), a transient conversation (the eval CLI, no id) stays in memory; the publications of the written row
     * (embedding, console line, realtime tick) run after the outermost commit. A duplicate the upsert does not fold under a
     * claim is a concurrent drive: logged and turned into the claim-lost exit.
     *
     * @throws AgentRunClaimLostException the run claim was lost while the claim-fenced write ran
     */
    public function persistMessageUnderRun(
        AgentRun $agentRun,
        AIConversationMessage $message,
        ?AgentConsoleStream $consoleStream = null,
        bool $emitRealtimeSignal = false
    ): void {
        // The message carries its conversation (wired on appendMessage) — no redundant $conversation parameter.
        // isset: never lazy-load — a row loaded by the turn is bound to the loop's conversation
        // (AIConversationMessagesService::bindLoadedMessagesToConversation); a lazily hydrated copy would carry a stale
        // runToken (the claim guard skipped) and no loaded messages (the capability chain bypassed)
        $conversation = isset($message->aiConversation) ? $message->aiConversation : null;
        // the `$from` bindings of a ComposedView block are resolved right before the row is written
        $this->composedViewBindingResolutionService()->resolveBindingsOfRowBeforePersist($message);
        // Per-tool-call persist timing: the breakdown of THIS persist, stamped phase by phase (complete as far as it
        // got when a phase throws) — the dispatch block carries it onto the call row's loop overhead.
        $agentRun->turnTimings->lastPersistBreakdownMs = ['claim' => 0, 'row' => 0, 'embed' => 0, 'signal' => 0, 'firebase' => 0, 'a2aCount' => 0, 'newRow' => false];
        if ($message->aiConversationId !== null) {
            // ONE claim-fenced write — the index lock, the conversation row locked with the claim check, the
            // row, the delegate-status merge and the capability-chain re-encode of later rows commit together or not at
            // all; a worker that lost its claim writes nothing. The token is the one THIS process claimed with (null on a
            // claimless CLI/eval drive), never the token another worker left on a loaded row.
            $runToken = $conversation !== null ? $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation) : null;
            $persistPhaseStartedAt = hrtime(true);
            try {
                // a transient conversation (the eval CLI, no id) or none at all: the row is written without a fence
                $writeScope = $conversation !== null ? $this->openClaimFencedWrite($conversation, $runToken) : AIConversationMessageWriteScope::unfenced();
                try {
                    $this->writeMessageOfRun($agentRun, $message, $conversation);
                    // a call row with its result (or a later step's head) resolves the pending
                    // `$from` bindings of the turn's rows — written in the same claim-fenced write as the row itself
                    $this->writeRowsWhoseComposedViewBindingsTheRowResolved($message, $conversation);
                } catch (\Throwable $writeError) {
                    $this->rollBackClaimFencedWrite($writeScope, $writeError);
                }
                $this->commitClaimFencedWrite($writeScope);
            } catch (UniqueConstraintViolationException $duplicateIndexViolation) {
                // Backstop of the UNIQUE index collision: a NEW row is a strict INSERT whose index collision is retried at the next index
                // (writeNewRowAtFreeIndex), so this catch fires only when every attempt collided. Under a
                // claim it is a concurrent-drive signal → log it loudly + ack-stop (the loop catches
                // AgentRunClaimLostException and exits silently), NEVER crash the handler. Without a claim (sync
                // CLI/eval) it is a genuine bug → rethrow.
                if ($runToken === null) {
                    throw $duplicateIndexViolation;
                }
                $this->toolCallDispatchService()->logToolFailure(
                    $conversation,
                    'persistMessage',
                    ['indexInConversation' => $message->indexInConversation],
                    'Concurrent drive defeated by UNIQUE(aiConversationId, indexInConversation) — investigate.',
                    $duplicateIndexViolation,
                    null
                );
                throw new AgentRunClaimLostException(
                    'Duplicate indexInConversation — concurrent drive defeated by the UNIQUE backstop.',
                    0,
                    $duplicateIndexViolation
                );
            } finally {
                $agentRun->turnTimings->lastPersistBreakdownMs['row'] = (int)round((hrtime(true) - $persistPhaseStartedAt) / 1e6);
            }
        } else {
            // a transient conversation (the eval CLI): the rows the stored row resolves change in memory only
            $this->writeRowsWhoseComposedViewBindingsTheRowResolved($message, $conversation);
        }
        // The publications of the written row run once it is visible to other processes — right away here, or after
        // the outermost commit when this persist joined an open claim-fenced write.
        $this->publishAfterCommit(AIConversationWrittenMessagePublication::ofWrittenMessage(
            $agentRun,
            $message,
            $conversation,
            $consoleStream,
            $emitRealtimeSignal,
            $this,
            $this->messagePublicationService(),
            $this->realtimeSignalService()
        ));
    }

    /**
     * The row write of {@see persistMessageUnderRun()}, run inside its claim-fenced write (or unfenced for a transient
     * conversation): a re-written row goes through the capability chain, a delegate call still RUNNING in memory takes
     * the terminal status its row holds, a NEW row is written at a free index; later rows are re-encoded after a re-write.
     */
    protected function writeMessageOfRun(AgentRun $agentRun, AIConversationMessage $message, ?AIConversation $conversation): void
    {
        // the row stores its capability set as a checkpoint or as its changes against the row
        // before; the resolved set returns to the message right after the write.
        $rowEncoding = null;
        $rowWritten = false;
        // A NEW row is encoded inside the new-row writer, after its index is final; here only a re-write.
        if ($conversation !== null && isset($conversation->messages) && isset($message->id)) {
            $rowEncoding = $conversation->messages->buildLoadedCapabilitiesChain()->buildRowEncoding($message);
            $rowEncoding->applyStoredForm();
        }
        try {
            // Legacy rows only: a `delegate` row written before sub-agent records existed moves its status after the
            // spawn; a sub-agent row's status is final at the spawn, so it is resolved here and skipped.
            // A delegate call already in the row and still RUNNING in THIS process: its children's workers may
            // have resolved the row status meanwhile. The row is read LOCKED inside this write's transaction, so
            // no child can resolve it between this read and the write — a terminal status in the row wins.
            $toolCallInMemory = $message->toolCall ?? null;
            if (isset($message->id) && $toolCallInMemory !== null && ($toolCallInMemory->name ?? '') === DelegateTool::NAME && !$toolCallInMemory->isResolved()) {
                $persistedStatus = $this->lockAndFindPersistedToolCallStatus($message);
                if ($persistedStatus !== null && in_array($persistedStatus, [AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_FAILED, AIConversationMessage::STATUS_CANCELLED], true)) {
                    $toolCallInMemory->status = $persistedStatus;
                }
            }
            // update(0) — the message IS the target; depth-default would re-upsert the in-memory
            // child set and re-trigger a parent-FK mis-stamp. A NEW row is written at a
            // free index above the PERSISTED newest one, so an owner row that landed mid-turn is never merged.
            if (!isset($message->id) && $conversation !== null && isset($conversation->id)) {
                $agentRun->turnTimings->lastPersistBreakdownMs['newRow'] = true;
                $this->persistNewRowUnderRunClaim($conversation, $message);
            } else {
                $this->writeExistingMessageRow($message);
            }
            $rowWritten = true;
            if ($rowEncoding !== null) {
                $this->markLoadedCapabilitiesRowPersisted($rowEncoding);
            }
        } finally {
            $rowEncoding?->restoreResolvedForm();
        }
        // An earlier row written again after later rows were persisted: those later rows' changes were computed
        // against its older state — re-encode them so each still resolves to the set it had at its own persist.
        if ($rowWritten && $conversation !== null && isset($conversation->messages)) {
            $this->persistReEncodedRowsAfter($conversation->messages, $message);
        }
    }

    /**
     * Write an EXISTING message row again (`update(0)` — the message IS the target). The record write guard:
     * a `delegate` row carrying a sub-agent record is written WITHOUT its `delegationResults` column — after the
     * spawn only the guarded record writers touch it (the admission, the run end, the timeout, the notifier), and a
     * re-persist of the parent's stale copy (a plan note, a capability re-encode) would merge an old run state back over
     * them. An unset property is not written; the record is restored on the message right after the write.
     */
    public function writeExistingMessageRow(AIConversationMessage $message): void
    {
        $subAgentRecordCarrier = $message->isDelegationCall() && isset($message->delegationResults) && !$message->delegationResults->isLegacyMap()
            ? $message->delegationResults
            : null;
        if ($subAgentRecordCarrier === null) {
            $message->update(0);
            return;
        }
        unset($message->delegationResults);
        try {
            $message->update(0);
        } finally {
            $message->delegationResults = $subAgentRecordCarrier;
        }
    }

    /**
     * Build a blank message, give it its TRANSIENT id from the tree counter when the conversation is never persisted
     * (eval / ephemeral — ids must be TREE-unique: framework identity is the global class id) and append it to
     * the conversation's loaded set (the stamping lives on {@see AIConversation::appendMessage()}); not persisted yet.
     */
    public function appendNewMessage(
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        string $messageType,
        string $content,
        ?string $role = null,
        ?string $authorType = null,
        ?RequestSnapshot $requestSnapshot = null,
        ?string $aiModelName = null,
        ?string $signalSource = null
    ): AIConversationMessage {
        $message = $this->newMessage();
        if (!isset($conversation->id)) {
            $message->id = $transientMessageIds->allocateId();
        }
        return $conversation->appendMessage($message, $messageType, $content, $role, $authorType, $requestSnapshot, $aiModelName, $signalSource);
    }

    /** Construction method of a blank message stamped with its creation time — a test double replaces it. */
    protected function newMessage(): AIConversationMessage
    {
        $message = AIConversationMessage::newInstance();
        $message->getChangeHistory()->createdTime = new DateTime();
        return $message;
    }

    /**
     * One loop SIGNAL that carries the usage of the step it answers (formerly written by the agent loop
     * itself): the step's response is not appended (a stall, a bounce, a retry, a stalled closing step), so its spend
     * rides the signal row — otherwise the model call would be invisible to the turn cost Σ, the derived step count AND
     * the compaction trigger (getLatestContextInputTokens). Stored role=SYSTEM (the wire build maps it to a user entry),
     * authored by the loop (AGENT), provenance `$signalSource`. `$keepsRequestSnapshot`: the step's request snapshot is
     * stamped on the row too when the conversation stores snapshots, so the exact request and the raw response stay
     * inspectable. Under append-only skill loading the row always carries the call's slim record (what it appended, the
     * anchor, the variants — {@see RequestSnapshot::buildRecordForRowOfDiscardedResponse()}): the row is the step's
     * marker, and the next call renders the appended item from it at the same position. Persisted before the loop moves on.
     */
    public function appendLoopSignalWithStepUsage(
        AgentRun $agentRun,
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        string $signalText,
        string $signalSource,
        ?string $modelName,
        ModelResponse $modelResponse,
        bool $keepsRequestSnapshot,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        $signalMessage = $this->appendNewMessage(
            $conversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_SIGNAL,
            $signalText,
            role: AIConversationMessage::ROLE_SYSTEM,
            authorType: AIConversationMessage::AUTHOR_AGENT,
            aiModelName: $modelName,
            signalSource: $signalSource
        );
        $signalMessage->applyUsage($modelResponse, includeRequestSnapshot: false);
        $recordedRequestSnapshot = $modelResponse->requestSnapshot?->buildRecordForRowOfDiscardedResponse(
            $keepsRequestSnapshot
                && $conversation->storesFullRequestSnapshots()
        );
        if ($recordedRequestSnapshot !== null) {
            $signalMessage->requestSnapshot = $recordedRequestSnapshot;
        }
        $this->persistMessageUnderRun($agentRun, $signalMessage, $consoleStream, $emitRealtimeSignal);
    }

    /**
     * Append the PENDING row of one tool call of the step (every call row is persisted PENDING right before its
     * own execution, the caller persists it). One message holds the call AND (once executed) its result + status — ALL
     * carried on the ToolCall VO, attached up front so the dispatch branches (incl. delegate) resolve it in place. The
     * row records the model that ISSUED the call, so per-message model attribution stays complete across
     * mid-conversation model/tier switches. `$stepHeadModelResponse` is given for the step's FIRST call row when the step
     * has no prose head: that row carries the step's usage stamp ({@see AIConversationMessage::applyUsageAsStepHead()}).
     */
    public function appendPendingToolCallRow(
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        ToolCall $toolCall,
        ?string $modelName,
        ?ModelResponse $stepHeadModelResponse
    ): AIConversationMessage {
        $callMessage = $this->appendNewMessage(
            $conversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_TOOL_CALL,
            '',
            authorType: AIConversationMessage::AUTHOR_ASSISTANT,
            aiModelName: $modelName
        );
        $callMessage->attachPendingToolCall($toolCall);
        if ($stepHeadModelResponse !== null) {
            $callMessage->applyUsageAsStepHead($stepHeadModelResponse);
        }
        return $callMessage;
    }

    /** The conversations service (inbound activity) — resolved inline (DI rule); a test double replaces it. */
    protected function conversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        return $aIConversationsService;
    }

    /** The run claims (the run token this process claimed, the live claim) — resolved inline (DI rule); a test double replaces it. */
    protected function runClaimsService(): AIConversationRunClaimsService
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService;
    }

    /** The publications of a written row — resolved inline (DI rule). */
    protected function messagePublicationService(): AIConversationMessagePublicationService
    {
        /** @var AIConversationMessagePublicationService $aIConversationMessagePublicationService */
        $aIConversationMessagePublicationService = DDDService::instance()->getService(AIConversationMessagePublicationService::class);
        return $aIConversationMessagePublicationService;
    }

    /** The tool-call dispatch, here for its tool-error log line only — resolved inline (DI rule). */
    protected function toolCallDispatchService(): AgentToolCallDispatchService
    {
        /** @var AgentToolCallDispatchService $agentToolCallDispatchService */
        $agentToolCallDispatchService = DDDService::instance()->getService(AgentToolCallDispatchService::class);
        return $agentToolCallDispatchService;
    }

    /** The `$from` resolution every row passes right before it is written (the method the tests override). */
    protected function composedViewBindingResolutionService(): ComposedViewBindingResolutionService
    {
        /** @var ComposedViewBindingResolutionService $composedViewBindingResolutionService */
        $composedViewBindingResolutionService = DDDService::instance()->getService(ComposedViewBindingResolutionService::class);
        return $composedViewBindingResolutionService;
    }

    /** The realtime service the widget row's tick goes through (the seam the tests override). */
    protected function realtimeSignalService(): AIConversationRealtimeSignalService
    {
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        return $aIConversationRealtimeSignalService;
    }

    /**
     * The rows whose pending `ComposedView` bindings the just written `$storedRow` resolved
     * ({@see ComposedViewBindingResolutionService::findRowsResolvedByStoredRow()}: a call row now carrying its result,
     * or the head of a later step), written back: their `message` column only (a partial write, no clobbering of a
     * column a concurrent path owns), inside the caller's claim-fenced write; the open chats are told by the realtime
     * tick once committed. A row of a transient conversation changes in memory only. In-memory work — no query for a
     * conversation without a pending binding.
     */
    protected function writeRowsWhoseComposedViewBindingsTheRowResolved(AIConversationMessage $storedRow, ?AIConversation $conversation): void
    {
        if ($conversation === null) {
            return;
        }
        $this->writeComposedViewRowsResolvedLater($conversation, $this->composedViewBindingResolutionService()->findRowsResolvedByStoredRow($storedRow));
    }

    /**
     * A turn boundary (the turn's end on every exit, the next turn's start): every still pending
     * `ComposedView` binding of the loaded rows is resolved once more and fails when it names a call no row carries
     * ({@see ComposedViewBindingResolutionService::findRowsResolvedAtTurnBoundary()}), so no widget waits forever; the
     * changed rows are written under the run claim (`message` column only) and announced by the realtime tick. In
     * memory only for a transient conversation; no query for a conversation without a pending binding.
     *
     * @throws AgentRunClaimLostException the run claim was lost — nothing is written
     */
    public function resolvePendingComposedViewBindingsAtTurnBoundary(AIConversation $conversation): void
    {
        if (!isset($conversation->messages)) {
            return;
        }
        $resolvedRows = $this->composedViewBindingResolutionService()->findRowsResolvedAtTurnBoundary($conversation);
        if ($resolvedRows === [] || !isset($conversation->id)) {
            return;
        }
        $writeScope = $this->openClaimFencedWrite($conversation, $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation));
        try {
            $this->writeComposedViewRowsResolvedLater($conversation, $resolvedRows);
        } catch (\Throwable $writeError) {
            $this->rollBackClaimFencedWrite($writeScope, $writeError);
        }
        $this->commitClaimFencedWrite($writeScope);
    }

    /**
     * Writes the `message` column of rows whose `ComposedView` bindings were resolved after their own persist, and
     * queues one realtime tick. A row not stored yet (no id) or of a transient conversation is left to memory.
     *
     * @param AIConversationMessage[] $resolvedRows
     */
    protected function writeComposedViewRowsResolvedLater(AIConversation $conversation, array $resolvedRows): void
    {
        if ($resolvedRows === [] || !isset($conversation->id)) {
            return;
        }
        foreach ($resolvedRows as $resolvedRow) {
            if (isset($resolvedRow->id)) {
                $this->writeMessageColumnOfRow($resolvedRow);
            }
        }
        $this->publishAfterCommit(ConversationWidgetRowChangeSignal::forConversation($conversation, $this->realtimeSignalService()));
    }

    /** The partial write of one row's `message` column (the method the tests override). */
    protected function writeMessageColumnOfRow(AIConversationMessage $message): void
    {
        $message->updatePartialIgnoringRights('message');
    }

    /**
     * The stored call rows of the conversation that carry one of the tool call ids, as the `$from` resolution reads them
     * (a call row outside the loaded window, e.g. compacted away): message id → id, status and raw
     * result and the `bindableResult` key of the `toolCall` column. One query over the conversation's rows; truncated rows excluded. Called only for a
     * `ComposedView` block that names an id the loaded rows do not hold.
     *
     * @param string[] $toolCallIds
     * @return ComposedViewToolCallResult[]
     * @throws \Doctrine\DBAL\Exception
     */
    public function findToolCallResultsByToolCallIds(AIConversation $conversation, array $toolCallIds): array
    {
        if (!isset($conversation->id) || $toolCallIds === []) {
            return [];
        }
        $messageTableName = DBAIConversationMessageModel::getTableName();
        $sqlDialect = EntityManagerFactory::getDialect();
        $toolCallIdSql = $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('toolCallId'));
        // `bindableResult` is an object: its text is decoded below, where the engines' spacing makes no difference
        $storedCallRows = EntityManagerFactory::getInstance()->getConnection()->fetchAllAssociative(
            "SELECT `id`, {$toolCallIdSql} AS `toolCallId`, " . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('status')) . ' AS `toolCallStatus`,'
            . ' ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('result')) . ' AS `toolCallResult`,'
            . ' ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('injectionScreening')) . ' AS `injectionScreening`,'
            . ' ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('bindableResult')) . ' AS `bindableResult`'
            . " FROM `{$messageTableName}` WHERE `aiConversationId` = :conversationId AND `truncatedAt` IS NULL AND `toolCall` IS NOT NULL"
            . " AND {$toolCallIdSql} IN (:toolCallIds)",
            ['conversationId' => (int)$conversation->id, 'toolCallIds' => array_values($toolCallIds)],
            ['toolCallIds' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $toolCallResultsByMessageId = [];
        foreach ($storedCallRows as $storedCallRow) {
            $toolCallResultsByMessageId[(int)$storedCallRow['id']] = ComposedViewToolCallResult::fromStoredValues(
                (string)$storedCallRow['toolCallId'],
                is_string($storedCallRow['toolCallStatus'] ?? null) ? $storedCallRow['toolCallStatus'] : null,
                is_string($storedCallRow['toolCallResult'] ?? null) && $storedCallRow['toolCallResult'] !== 'null' ? $storedCallRow['toolCallResult'] : null,
                is_string($storedCallRow['bindableResult'] ?? null) ? (json_decode($storedCallRow['bindableResult'], true) ?: null) : null,
                ($storedCallRow['injectionScreening'] ?? null) === ToolCall::INJECTION_SCREENING_REMOVED
            );
        }
        return $toolCallResultsByMessageId;
    }

    /**
     * Persist a NEW row of the turn this process drives — a claim-fenced write ({@see openClaimFencedWrite()}) around the free-index write
     * of {@see persistInboundRowIndexSafe()}: a worker that lost its claim appends nothing.
     *
     * @throws AgentRunClaimLostException
     * @throws MessageIndexLockUnavailableException
     */
    public function persistNewRowUnderRunClaim(AIConversation $conversation, AIConversationMessage $message): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        $writeScope = $this->openClaimFencedWrite($conversation, $aIConversationRunClaimsService->getRunTokenClaimedInThisProcess($conversation));
        try {
            $this->writeNewRowAtFreeIndex($conversation, $message, false);
        } catch (\Throwable $writeError) {
            $this->rollBackClaimFencedWrite($writeScope, $writeError);
        }
        $this->commitClaimFencedWrite($writeScope);
    }

    /**
     * One attempt to take the named lock with the 3 s wait — true when this session now holds it (the method the tests
     * override). MariaDB: `GET_LOCK` on the session. PostgreSQL: the framework's lock of the open transaction, which
     * {@see beginTransactionForMessageIndexLock()} has made sure of.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function acquireMessageIndexLock(string $lockName): bool
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        if (static::isMessageIndexLockHeldForOneTransaction()) {
            return EntityManagerFactory::getDialect()->acquireTransactionLock($connection, $lockName, 3);
        }
        return (int)$connection->fetchOne('SELECT GET_LOCK(:name, 3)', ['name' => $lockName]) === 1;
    }

    /**
     * Release the named lock; a failure is swallowed — the lock expires with the connection. A lock held for one
     * transaction has ended with that transaction.
     */
    protected function releaseMessageIndexLock(string $lockName): void
    {
        try {
            $connection = EntityManagerFactory::getInstance()->getConnection();
            if (static::isMessageIndexLockHeldForOneTransaction()) {
                EntityManagerFactory::getDialect()->releaseTransactionLock($connection, $lockName);
                return;
            }
            $connection->executeStatement('SELECT RELEASE_LOCK(:name)', ['name' => $lockName]);
        } catch (\Throwable) {
            // the lock expires with the connection
        }
    }

    /**
     * Persist a NEW message row collision-free: under the conversation's index lock, the
     * row is written at the index it was allocated in memory when that index is still FREE; only an index another
     * writer has already taken (the API's owner message or click that landed mid-turn from its own view of the
     * newest index) re-anchors the row above the PERSISTED newest index, so the two rows never merge in the
     * INSERT … ON DUPLICATE KEY UPDATE upsert. A free index is kept on purpose: a step issues several tool calls at
     * once and persists only its head row before dispatch — a row a later call of the same step emits DURING its
     * execution (a purpose-consequence signal, a tool message) lands above the head, and the call row that follows
     * must still take its own lower index, else the step's calls stop being contiguous in the history and the
     * provider rejects the next request (an assistant tool-calls message whose results are split by a system row).
     * The one persist path every new-row writer uses; the in-memory object is the one already in the conversation's
     * set, so a re-anchor keeps `getNextIndex()` consistent. Ephemeral conversations (no id) are written in memory
     * only.
     *
     * INBOUND rows only — written by a process that does NOT drive the conversation's turn, so no run
     * claim fences them: the owner's message and clicks (`injectSignal`, `driveConversation`, the CLI), voice
     * transcripts and call signals, the phone kickoff, schedule notices into the scheduling conversation, the legacy
     * reply path, the eval owner. A row of the turn this process drives goes through
     * {@see persistNewRowUnderRunClaim()}; a test of the application pins the caller list.
     *
     * @throws MessageIndexLockUnavailableException
     */
    public function persistInboundRowIndexSafe(AIConversation $conversation, AIConversationMessage $message): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $messageIndexLock = $this->lockMessageIndex($conversation);
        try {
            $this->writeNewRowAtFreeIndex($conversation, $message, true);
        } finally {
            $this->unlockMessageIndex($messageIndexLock);
        }
    }

    /**
     * The body of {@see persistInboundRowIndexSafe()} and {@see persistNewRowUnderRunClaim()}, run by their callers while
     * they hold the conversation's index lock: keep the allocated index while it is free, else re-anchor above the
     * persisted newest index, then write the row. `$isInboundRow`: the writer does not drive the conversation's turn.
     *
     * The row is written by a strict INSERT, so the database itself refuses an index another writer took meanwhile (the
     * named index lock is not exclusive behind ProxySQL + Galera): the row is written again one above the refused index,
     * up to {@see self::NEW_ROW_INDEX_COLLISION_ATTEMPTS} attempts. MariaDB undoes only the refused statement, so the
     * retry runs in the caller's open transaction; PostgreSQL ends the whole transaction with the refused statement and
     * gets no second attempt ({@see canWriteAgainAfterARefusedInsert()}). The retry reads nothing: the winner was committed after this
     * transaction's snapshot, a plain read does not see it, and a locking read of it fails with error 1020 under
     * `innodb_snapshot_isolation` — the INSERT itself is the probe.
     *
     * @throws UniqueConstraintViolationException the index stayed taken on every attempt
     */
    protected function writeNewRowAtFreeIndex(AIConversation $conversation, AIConversationMessage $message, bool $isInboundRow): void
    {
        $refusedIndex = null;
        for ($attempt = 1; ; $attempt++) {
            try {
                $this->writeNewRowAtFreeIndexOnce($conversation, $message, $isInboundRow, $refusedIndex);
                return;
            } catch (UniqueConstraintViolationException $indexCollision) {
                if ($attempt >= self::NEW_ROW_INDEX_COLLISION_ATTEMPTS || !$this->canWriteAgainAfterARefusedInsert()) {
                    throw $indexCollision;
                }
                $refusedIndex = $message->indexInConversation;
            }
        }
    }

    /**
     * Whether the open transaction can go on after the database refused an INSERT. MariaDB undoes only the refused
     * statement. PostgreSQL ends the transaction with it — and has no use for a second attempt: its message-index lock
     * is exclusive and its writers read the latest commits, so a writer under the lock does not meet an index another
     * writer took meanwhile; a refusal there fails the write.
     */
    protected function canWriteAgainAfterARefusedInsert(): bool
    {
        return !EntityManagerFactory::getDialect()->capabilities()->isPostgres();
    }

    /**
     * One attempt of {@see writeNewRowAtFreeIndex()}. `$refusedIndex`: the index the previous attempt's INSERT was refused
     * at — a row this transaction's snapshot cannot see holds it, so it counts as the persisted newest and the row
     * re-anchors above it.
     *
     * @throws UniqueConstraintViolationException
     */
    protected function writeNewRowAtFreeIndexOnce(AIConversation $conversation, AIConversationMessage $message, bool $isInboundRow, ?int $refusedIndex): void
    {
        $allocatedIndex = $refusedIndex !== null ? null : ($message->indexInConversation ?? null);
        $persistedNewestIndex = $this->findNewestMessageIndex($conversation);
        if ($refusedIndex !== null) {
            $persistedNewestIndex = max($persistedNewestIndex ?? -1, $refusedIndex);
        }
        // The in-memory view is CURRENT when its newest index reaches the persisted newest — a conversation
        // fetched by a plain find carries only the oldest window, so its allocation answers "window size" and
        // must be re-anchored even where that index happens to be free (a gap mid-history).
        $inMemoryViewIsCurrent = isset($conversation->messages)
            && $conversation->messages->getNextIndex() - 1 >= ($persistedNewestIndex ?? -1);
        if ($allocatedIndex === null || !$inMemoryViewIsCurrent || $this->isMessageIndexTaken($conversation, $allocatedIndex)) {
            $message->indexInConversation = ($persistedNewestIndex ?? -1) + 1;
            // A re-anchored row may no longer be the set's last element by APPEND order (a row appended after it
            // kept a lower index) — re-sort so `last()` is the newest index again: the wire-range guard compares
            // the context tail (anchored on `last()`) with `getNextIndex() - 1`.
            if (isset($conversation->messages)) {
                $conversation->messages->sortByIndexInConversation();
            }
        }
        // every new row goes through here — after its final index is known. A row this process can place in
        // the conversation's capability chain is stored as a checkpoint or as its changes; a row written from a stale
        // view drops the set it carried, so it can never hide newer changes behind an outdated set. A view is stale when
        // it holds an unhydrated window of stored rows. An INBOUND row's view is stale, too, when another process drives
        // the turn: the turn's rows below it may still be written again in place (a call row finalized after its tool
        // loaded skills), which no index comparison sees; or when persisted rows above the newest row it holds carry a
        // set (a turn worker wrote its changes after this process loaded). Either way the carried set predates the
        // turn's and an owner row would store it as the next turn's checkpoint. The turn's own rows never drop for a
        // lag: the rows their writer misses are inbound rows, which carry no newer set than the turn's — a lag over rows
        // without a set is no reason to drop either. A dropped row reads like any row without a set — readers fall back
        // to the newest row that carries one.
        $rowEncoding = null;
        if (isset($message->loadedCapabilities)) {
            $capabilityChain = isset($conversation->messages) ? $conversation->messages->buildLoadedCapabilitiesChain() : null;
            if ($capabilityChain !== null && $capabilityChain->canPlaceNewRows()
                && !($isInboundRow && $this->inboundViewMissesTheTurnsLoadedCapabilities($conversation, $message, $persistedNewestIndex))) {
                $rowEncoding = $capabilityChain->buildRowEncoding($message);
                $rowEncoding->applyStoredForm();
            } else {
                $message->dropCarriedLoadedCapabilities();
            }
        }
        try {
            $this->writeMessageRow($message);
            if ($rowEncoding !== null) {
                $this->markLoadedCapabilitiesRowPersisted($rowEncoding);
            }
        } finally {
            $rowEncoding?->restoreResolvedForm();
        }
    }

    /**
     * Persist a signal of a source a running turn takes
     * over ({@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource::isPickedUpByRunningTurn()}) as WAITING
     * ({@see AIConversationMessage::STATUS_PENDING} — no model call has read it yet). With
     * `$mergeIntoWaitingSignalOfSameSource` (the caller saw a live run claim) a row of the same source that is still
     * waiting takes the new text instead of a second row being appended — one row, the newest text. Runs under the
     * conversation's index lock, the same lock {@see findAndMarkReadSignalsWaitingForRunningTurn()} holds while it
     * reads and marks the waiting rows, so a merged text is either read by the loop or lands in a new row, never lost.
     * Returns whether the text was merged into an existing row (the message then carries that row's id and index).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function persistSignalWaitingForRunningTurn(
        AIConversation $conversation,
        AIConversationMessage $signalMessage,
        bool $mergeIntoWaitingSignalOfSameSource,
        bool $mergeOnlyIdenticalText = false
    ): bool {
        $signalMessage->status = AIConversationMessage::STATUS_PENDING;
        if (!isset($conversation->id)) {
            return false;
        }
        $messageIndexLock = $this->lockMessageIndex($conversation);
        try {
            if ($mergeIntoWaitingSignalOfSameSource) {
                $waitingSignal = $this->findNewestSignalWaitingForRunningTurnOfSource($conversation, (string)($signalMessage->signalSource ?? ''));
                // `$mergeOnlyIdenticalText` (an owner-authored signal): a doubled click folds into its waiting row,
                // a different click is a new row — two answers are never collapsed into "the newest text".
                // A waiting row that already holds this very text needs no write and is the merged row: under the index
                // lock no loop has read it since the read above. (An UPDATE that changes nothing reports no affected
                // row on MariaDB and one on PostgreSQL, so its count cannot answer for that case.)
                if ($waitingSignal !== null
                    && (!$mergeOnlyIdenticalText || $waitingSignal->hasSameTextIgnoringSurroundingWhitespace((string)($signalMessage->message ?? '')))
                    && ($waitingSignal->message === (string)($signalMessage->message ?? '')
                        || $this->replaceTextOfSignalWaitingForRunningTurn($waitingSignal->messageId, (string)($signalMessage->message ?? '')))
                ) {
                    $signalMessage->id = $waitingSignal->messageId;
                    $signalMessage->indexInConversation = $waitingSignal->indexInConversation;
                    return true;
                }
            }
            $this->writeNewRowAtFreeIndex($conversation, $signalMessage, true);
            return false;
        } finally {
            $this->unlockMessageIndex($messageIndexLock);
        }
    }

    /**
     * The one write of an INBOUND signal row: a model-VISIBLE, user-INVISIBLE SIGNAL appended to the
     * conversation's set ({@see AIConversation::appendMessage()} stays repo-agnostic) with the owner's device, then
     * persisted and the inbound activity stamped. Skipped on an ephemeral conversation (no DB id): nothing to persist into.
     *
     * The signal must be the NEWEST message: a conversation fetched by a plain find carries only the lazy OLDEST window of
     * its messages, so the appended index would land mid-history and the owed-work derive (which reads past the
     * compaction cover only) would never see it (index 50 of 66 — the rebuild request was
     * silently never answered). The row is therefore re-anchored on the persisted newest index under the conversation's
     * index lock ({@see persistInboundRowIndexSafe()}) — the worker persisting its next row at the same moment allocates
     * ABOVE this one instead of merging into it.
     *
     * A signal of a source a running turn takes over ({@see \DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSource::isPickedUpByRunningTurn()})
     * is written WAITING ({@see persistSignalWaitingForRunningTurn()}): while a live run claim exists, a waiting row of the same source takes the new text instead — an
     * owner-authored signal only when the text is the same (a doubled click): two different clicks are two answers and land
     * as two rows. A conversation's START signal (`$isStartSignal`: the drive's invisible start
     * context, the phone kickoff) is always written index-safe and carries no device. `$loadedCapabilities`: the purpose's
     * kickoff capabilities the row carries. The caller passes the text already sanitized and capped. Returns the appended
     * SIGNAL message (after a merge it carries the waiting row's id and index).
     *
     * @throws MessageIndexLockUnavailableException
     * @throws \Doctrine\DBAL\Exception
     */
    public function appendInboundSignal(
        AIConversation $conversation,
        string $signalText,
        string $signalSource,
        ?OwnerDevice $ownerDevice = null,
        ?LoadedCapabilities $loadedCapabilities = null,
        bool $isStartSignal = false
    ): AIConversationMessage {
        $signalMessage = AIConversationMessage::newInstance();
        $conversation->appendMessage(
            $signalMessage,
            AIConversationMessage::MESSAGE_SIGNAL,
            $signalText,
            AIConversationMessage::ROLE_SYSTEM,
            AIConversationMessage::AUTHOR_AGENT,
            signalSource: $signalSource
        );
        // Owner channel awareness: an owner's click carries the device it came from — persisted for the
        // inspector and the surface derivation; a SIGNAL row's wire form never renders a bracket (bytes unchanged). A
        // start signal is the platform's own row and carries no device.
        if (!$isStartSignal) {
            $signalMessage->ownerDevice = $ownerDevice;
        }
        if ($loadedCapabilities !== null) {
            $signalMessage->loadedCapabilities = $loadedCapabilities;
        }
        if (!isset($conversation->id)) {
            return $signalMessage;
        }
        $aIConversationsService = $this->conversationsService();
        if (!$isStartSignal && ConversationSignalSources::tryFrom($signalSource)?->isPickedUpByRunningTurn() === true) {
            $mergedIntoWaitingSignal = $this->persistSignalWaitingForRunningTurn(
                $conversation,
                $signalMessage,
                mergeIntoWaitingSignalOfSameSource: $this->runClaimsService()->hasLiveRunClaim($conversation),
                mergeOnlyIdenticalText: $signalMessage->isOwnerAuthoredInbound()
            );
            $this->aiTrace('signal.waitingForRunningTurn.persisted', ['conversationId' => $conversation->id, 'signalSource' => $signalSource, 'merged' => $mergedIntoWaitingSignal]);
        } else {
            $this->persistInboundRowIndexSafe($conversation, $signalMessage);
        }
        // a SIGNAL is turn-owing inbound — stamp activity + re-arm the idle-finalize cycle. A signal that owes
        // no work (the widget render report) is information only: it re-arms nothing, so no idle pass
        // resumes a turn for it.
        if (!$signalMessage->isSignalThatOwesNoWork()) {
            $aIConversationsService->recordInboundMessageActivity($conversation);
        }
        return $signalMessage;
    }

    /**
     * The newest row of this conversation that is a WAITING signal of the given source, or null.
     *
     * @param AIConversation $conversation
     * @param string $signalSource
     * @return AIConversationWaitingSignal|null
     * @throws \Doctrine\DBAL\Exception
     */
    public function findNewestSignalWaitingForRunningTurnOfSource(AIConversation $conversation, string $signalSource): ?AIConversationWaitingSignal
    {
        if (!isset($conversation->id)) {
            return null;
        }
        $waitingSignalRow = EntityManagerFactory::getInstance()->getConnection()->fetchAssociative(
            'SELECT `id`, `indexInConversation`, `message` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `messageType` = :signalType AND `signalSource` = :signalSource'
            . ' AND `status` = :pending AND `truncatedAt` IS NULL ORDER BY `indexInConversation` DESC LIMIT 1',
            [
                'conversationId' => (int)$conversation->id,
                'signalType' => AIConversationMessage::MESSAGE_SIGNAL,
                'signalSource' => $signalSource,
                'pending' => AIConversationMessage::STATUS_PENDING,
            ]
        );
        if ($waitingSignalRow === false) {
            return null;
        }
        return AIConversationWaitingSignal::forMessageRow(
            (int)$waitingSignalRow['id'],
            (int)$waitingSignalRow['indexInConversation'],
            (string)($waitingSignalRow['message'] ?? '')
        );
    }

    /**
     * Compare-and-set: the waiting signal row takes the new text only while it is still WAITING. Returns whether it
     * did (0 affected rows = a loop read it meanwhile — the caller appends a new row instead). Called with a text
     * other than the one the row holds: MariaDB reports no affected row for a write that changes nothing.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function replaceTextOfSignalWaitingForRunningTurn(int $messageId, string $signalText): bool
    {
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' SET `message` = :signalText WHERE `id` = :id AND `status` = :pending',
            ['signalText' => $signalText, 'id' => $messageId, 'pending' => AIConversationMessage::STATUS_PENDING]
        ) === 1;
    }

    /**
     * Whether this conversation has a WAITING signal row above the given index — one indexed read, run by the loop
     * before every model call and by the release paths right after the claim is released (a signal whose injection
     * saw the live claim and dispatched no resume, but landed after the release path's last read). Only signal rows
     * of the picked-up sources are ever written WAITING; a tool-call row's `status` column is excluded by the type.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function hasSignalWaitingForRunningTurn(AIConversation $conversation, int $aboveIndex = -1): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `indexInConversation` > :aboveIndex AND `messageType` = :signalType'
            . ' AND `signalSource` IN (:signalSources) AND `status` = :pending AND `truncatedAt` IS NULL',
            [
                'conversationId' => (int)$conversation->id,
                'aboveIndex' => $aboveIndex,
                'signalType' => AIConversationMessage::MESSAGE_SIGNAL,
                'signalSources' => ConversationSignalSources::getValuesPickedUpByRunningTurn(),
                'pending' => AIConversationMessage::STATUS_PENDING,
            ],
            ['signalSources' => ArrayParameterType::STRING]
        ) > 0;
    }

    /**
     * How many WAITING signal rows of one source this conversation holds (the run-end notifications no
     * model call has read yet) — one indexed read, polled by the short wait at the head of a notification-started turn.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function countSignalsWaitingForRunningTurnOfSource(AIConversation $conversation, string $signalSource): int
    {
        if (!isset($conversation->id)) {
            return 0;
        }
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `messageType` = :signalType AND `signalSource` = :signalSource'
            . ' AND `status` = :pending AND `truncatedAt` IS NULL',
            [
                'conversationId' => (int)$conversation->id,
                'signalType' => AIConversationMessage::MESSAGE_SIGNAL,
                'signalSource' => $signalSource,
                'pending' => AIConversationMessage::STATUS_PENDING,
            ]
        );
    }

    /**
     * The running loop takes over every WAITING signal row: under the conversation's index lock it reads them (id,
     * index, source, text) and marks them read ({@see AIConversationMessage::STATUS_COMPLETED}) in one guarded
     * statement, so an injection merging a newer text either happened before the read (the loop gets the new text) or
     * finds the row read and appends a new one. Returns the rows as detached message objects, in index order.
     *
     * @return AIConversationMessage[]
     * @throws \Doctrine\DBAL\Exception
     */
    public function findAndMarkReadSignalsWaitingForRunningTurn(AIConversation $conversation): array
    {
        if (!isset($conversation->id)) {
            return [];
        }
        $messageIndexLock = $this->lockMessageIndex($conversation);
        try {
            $connection = EntityManagerFactory::getInstance()->getConnection();
            $tableName = DBAIConversationMessageModel::getTableName();
            $waitingSignalRows = $connection->fetchAllAssociative(
                'SELECT `id`, `indexInConversation`, `signalSource`, `message` FROM `' . $tableName . '`'
                . ' WHERE `aiConversationId` = :conversationId AND `messageType` = :signalType'
                . ' AND `signalSource` IN (:signalSources) AND `status` = :pending AND `truncatedAt` IS NULL'
                . ' ORDER BY `indexInConversation` ASC',
                [
                    'conversationId' => (int)$conversation->id,
                    'signalType' => AIConversationMessage::MESSAGE_SIGNAL,
                    'signalSources' => ConversationSignalSources::getValuesPickedUpByRunningTurn(),
                    'pending' => AIConversationMessage::STATUS_PENDING,
                ],
                ['signalSources' => ArrayParameterType::STRING]
            );
            if ($waitingSignalRows === []) {
                return [];
            }
            $waitingSignalIds = array_map(static fn (array $waitingSignalRow): int => (int)$waitingSignalRow['id'], $waitingSignalRows);
            $connection->executeStatement(
                'UPDATE `' . $tableName . '` SET `status` = :completed WHERE `id` IN (:ids) AND `status` = :pending',
                ['completed' => AIConversationMessage::STATUS_COMPLETED, 'ids' => $waitingSignalIds, 'pending' => AIConversationMessage::STATUS_PENDING],
                ['ids' => ArrayParameterType::INTEGER]
            );
            $readSignals = [];
            foreach ($waitingSignalRows as $waitingSignalRow) {
                $readSignal = AIConversationMessage::newInstance();
                $readSignal->id = (int)$waitingSignalRow['id'];
                $readSignal->aiConversationId = (int)$conversation->id;
                $readSignal->indexInConversation = (int)$waitingSignalRow['indexInConversation'];
                $readSignal->messageType = AIConversationMessage::MESSAGE_SIGNAL;
                $readSignal->signalSource = (string)$waitingSignalRow['signalSource'];
                $readSignal->message = (string)$waitingSignalRow['message'];
                $readSignal->status = AIConversationMessage::STATUS_COMPLETED;
                $readSignals[] = $readSignal;
            }
            return $readSignals;
        } finally {
            $this->unlockMessageIndex($messageIndexLock);
        }
    }

    /**
     * The release path's mark for a WAITING signal row it has handled (replayed, consumed or left owed at the tail):
     * the row is marked read only when its text is still the one the release path loaded — an injection that merged
     * a newer text in between keeps the row WAITING, and the caller re-fires the turn for it.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function markSignalReadUnlessItsTextChanged(AIConversationMessage $signalMessage): bool
    {
        if (!isset($signalMessage->id)) {
            return false;
        }
        $marked = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' SET `status` = :completed WHERE `id` = :id AND `status` = :pending AND `message` = :loadedText',
            [
                'completed' => AIConversationMessage::STATUS_COMPLETED,
                'id' => (int)$signalMessage->id,
                'pending' => AIConversationMessage::STATUS_PENDING,
                'loadedText' => (string)($signalMessage->message ?? ''),
            ]
        ) === 1;
        if ($marked) {
            // a fenced write that rolls back leaves the signal waiting — in memory as in the row
            $this->undoOnRollback(AIConversationMessageStatusAndTextUndo::ofMessage($signalMessage));
            $signalMessage->status = AIConversationMessage::STATUS_COMPLETED;
        }
        return $marked;
    }

    /**
     * A turn that ends on its terminal ERROR and report (the AI budget, the turn's step or cost cap) marks the run-end
     * notifications ({@see AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED}) still WAITING read where they stand.
     * Left waiting, nothing takes them until a later genuine turn, whose first model call replays them at
     * the tail as new input — possibly days later, with no sense of their age. Marked read they stay model-visible at
     * their own index, in time order; the run's result stays on its record, which `check_delegations` reads. A run end
     * that arrives after this mark is a new notification and resumes the conversation as usual.
     *
     * Called only once the ERROR row and the report row are persisted: a turn that fails before (a lost index lock, a
     * transport failure, any turn error) is released OWED and re-driven, and its re-driven turn still finds the run ends
     * waiting. The mark is a claim-fenced write on the run token THIS process holds
     * ({@see openClaimFencedWrite()}): a worker whose claim was lost — another worker drives or re-drives the
     * conversation — marks nothing, so a turn of the new owner never loses its input, and the claim loss is rethrown like
     * that of every fenced write of the turn; a drive without a claim (CLI, eval) marks unfenced. The loaded copies are
     * marked as well. Any other failure is fail-soft: the rows stay waiting, as before. Returns how many rows were marked.
     *
     * @throws AgentRunClaimLostException the claim behind this process's run token is no longer held — nothing is marked
     */
    public function markWaitingSubAgentRunEndNotificationsReadAtTerminalTurnEnd(AIConversation $conversation): int
    {
        if (!isset($conversation->id)) {
            return 0;
        }
        try {
            $writeScope = $this->openClaimFencedWrite($conversation, $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation));
            try {
                $connection = EntityManagerFactory::getInstance()->getConnection();
                $waitingNotificationIds = array_map('intval', $connection->fetchFirstColumn(
                    'SELECT `id` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
                    . ' WHERE `aiConversationId` = :conversationId AND `messageType` = :signalType AND `signalSource` = :signalSource'
                    . ' AND `status` = :pending AND `truncatedAt` IS NULL',
                    [
                        'conversationId' => (int)$conversation->id,
                        'signalType' => AIConversationMessage::MESSAGE_SIGNAL,
                        'signalSource' => AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED,
                        'pending' => AIConversationMessage::STATUS_PENDING,
                    ]
                ));
                $markedCount = $waitingNotificationIds === [] ? 0 : (int)$connection->executeStatement(
                    'UPDATE `' . DBAIConversationMessageModel::getTableName() . '` SET `status` = :completed WHERE `id` IN (:ids) AND `status` = :pending',
                    ['completed' => AIConversationMessage::STATUS_COMPLETED, 'ids' => $waitingNotificationIds, 'pending' => AIConversationMessage::STATUS_PENDING],
                    ['ids' => ArrayParameterType::INTEGER]
                );
            } catch (\Throwable $writeError) {
                $this->rollBackClaimFencedWrite($writeScope, $writeError);
            }
            $this->commitClaimFencedWrite($writeScope);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost;
        } catch (\Throwable $markError) {
            $this->aiTrace('turn.terminal.runEndNotificationsMarkFailed', ['conversationId' => $conversation->id, 'error' => $markError->getMessage()]);
            return 0;
        }
        if ($waitingNotificationIds === []) {
            return 0;
        }
        foreach ($waitingNotificationIds as $waitingNotificationId) {
            $loadedNotification = isset($conversation->messages) ? $conversation->messages->getMessageById($waitingNotificationId) : null;
            if ($loadedNotification !== null) {
                $loadedNotification->status = AIConversationMessage::STATUS_COMPLETED;
            }
        }
        $this->aiTrace('turn.terminal.runEndNotificationsMarkedRead', ['conversationId' => $conversation->id, 'messageIds' => $waitingNotificationIds, 'marked' => $markedCount]);
        return $markedCount;
    }

    /**
     * Whether a row at this index already exists for the conversation — a row another writer persisted. A consistent
     * read on the caller's connection: under the conversation's index lock every lock-respecting writer's row is
     * committed before this runs, which is the same guarantee {@see findNewestMessageIndex} relies on.
     */
    public function isMessageIndexTaken(AIConversation $conversation, int $indexInConversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $existingId = $connection->fetchOne(
            'SELECT `id` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `indexInConversation` = :indexInConversation LIMIT 1',
            ['conversationId' => (int)$conversation->id, 'indexInConversation' => $indexInConversation]
        );
        return $existingId !== null && $existingId !== false;
    }

    /**
     * Whether the view an inbound row is written from may miss the loaded capabilities of the conversation's turn: rows
     * it does not hold carry a set, or another process drives the turn right now.
     */
    protected function inboundViewMissesTheTurnsLoadedCapabilities(AIConversation $conversation, AIConversationMessage $message, ?int $persistedNewestIndex): bool
    {
        return $this->viewMissesPersistedLoadedCapabilities($conversation, $message, $persistedNewestIndex)
            || $this->isTurnDrivenByAnotherProcess($conversation);
    }

    /**
     * Whether a live run claim ({@see AIConversationRunClaimsService::hasLiveRunClaim()}: RUNNING with a fresh heartbeat) is held
     * on the conversation by a process other than this one. A process that holds the claim (or works under it, a tool
     * subprocess) writes from the turn's own view. One primary-key read.
     */
    protected function isTurnDrivenByAnotherProcess(AIConversation $conversation): bool
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService->getRunTokenClaimedInThisProcess($conversation) === null
            && $aIConversationRunClaimsService->hasLiveRunClaim($conversation);
    }

    /**
     * Whether persisted rows the conversation's in-memory view does not hold carry a capability set: the view ends at the
     * newest index among its rows other than `$message` (the row being written, whose allocated index another writer
     * may hold), and only a persisted newest index above that end asks the database.
     */
    protected function viewMissesPersistedLoadedCapabilities(AIConversation $conversation, AIConversationMessage $message, ?int $persistedNewestIndex): bool
    {
        $viewNewestIndex = -1;
        foreach ($conversation->messages->getElements() as $row) {
            if ($row !== $message && ($row->indexInConversation ?? -1) > $viewNewestIndex) {
                $viewNewestIndex = (int)$row->indexInConversation;
            }
        }
        return ($persistedNewestIndex ?? -1) > $viewNewestIndex
            && $this->hasPersistedLoadedCapabilitiesAboveIndex($conversation, $viewNewestIndex);
    }

    /**
     * Whether a persisted, untruncated row of the conversation ABOVE `$indexInConversation` carries a capability set
     * (a checkpoint or a change record) — the rows a process whose view ends at that index has not seen. Read under the
     * conversation's index lock, like {@see findNewestMessageIndex()}.
     */
    public function hasPersistedLoadedCapabilitiesAboveIndex(AIConversation $conversation, int $indexInConversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $carryingRowId = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT `id` FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `indexInConversation` > :indexInConversation'
            . ' AND `loadedCapabilities` IS NOT NULL AND `truncatedAt` IS NULL LIMIT 1',
            ['conversationId' => (int)$conversation->id, 'indexInConversation' => $indexInConversation]
        );
        return $carryingRowId !== null && $carryingRowId !== false;
    }

    /**
     * The NEW row's write itself — a strict INSERT (an index another writer took throws instead of merging both rows into
     * one); the seam the container-less unit test overrides.
     *
     * @throws UniqueConstraintViolationException
     */
    protected function writeMessageRow(AIConversationMessage $message): void
    {
        $message->insert(0);
    }

    /**
     * The newest PERSISTED `indexInConversation` of a conversation (null = no messages) — one MAX() query, independent of
     * whatever window is loaded on the entity. A conversation fetched by a plain find carries only the lazy-loaded
     * OLDEST window of its messages, so `AIConversationMessages::getNextIndex()` on it answers `window size`, not
     * `newest + 1`: an injected SIGNAL then lands mid-history (index 50 in a 66-message conversation, observed
     * in a real conversation), invisible to the owed-work derive (which reads only past the compaction
     * cover) — the request was silently never answered. Callers appending OUTSIDE the running turn use this.
     */
    /** The conversation's highest persisted message id — one MAX() query, independent of the loaded window. */
    public function findHighestMessageId(AIConversation $conversation): ?int
    {
        if (!isset($conversation->id)) {
            return null;
        }
        $highestId = EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            'SELECT MAX(`id`) FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `aiConversationId` = :conversationId',
            ['conversationId' => (int)$conversation->id]
        );
        return $highestId === null || $highestId === false ? null : (int)$highestId;
    }

    public function findNewestMessageIndex(AIConversation $conversation): ?int
    {
        if (!isset($conversation->id)) {
            return null;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $newestIndex = $connection->fetchOne(
            'SELECT MAX(`indexInConversation`) FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `aiConversationId` = :conversationId',
            ['conversationId' => (int)$conversation->id]
        );
        return $newestIndex === null || $newestIndex === false ? null : (int)$newestIndex;
    }

    /**
     * How often a conversation has already COMPLETED a call of `$toolName` whose arguments carry every given value —
     * one COUNT() over the persisted rows, independent of the loaded window and of turn boundaries. A tool uses this to
     * see its own history in the conversation that calls it (a sub-agent repeated the same search many times in
     * one run because every new result pushed the earlier ones off the wire); the current PENDING call is not
     * counted, so the result is the number of EARLIER completed calls.
     *
     * @param int $aiConversationId the conversation whose rows are counted
     * @param string $toolName the tool name as persisted in `toolCall.name`
     * @param array<string, string> $argumentValues top-level `toolCall.arguments` keys that must equal the given strings
     * @return int
     * @throws \Doctrine\DBAL\Exception
     */
    public function countCompletedToolCallsInConversation(int $aiConversationId, string $toolName, array $argumentValues = []): int
    {
        $sqlDialect = EntityManagerFactory::getDialect();
        $sql = 'SELECT COUNT(*) FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `virtualToolName` = :toolName'
            . ' AND ' . $sqlDialect->jsonText('`toolCall`', JsonPath::fromKeys('status')) . ' = :completedStatus';
        $parameters = [
            'conversationId' => $aiConversationId,
            'toolName' => $toolName,
            'completedStatus' => AIConversationMessage::STATUS_COMPLETED,
        ];
        $argumentIndex = 0;
        foreach ($argumentValues as $argumentName => $argumentValue) {
            $argumentIndex++;
            // the argument name is bound as one key of the path, never written into the statement
            $argumentPath = JsonPath::fromKeys('arguments')->withParameter(":argumentName$argumentIndex");
            $sql .= ' AND ' . $sqlDialect->jsonText('`toolCall`', $argumentPath) . " = :argumentValue$argumentIndex";
            $parameters["argumentName$argumentIndex"] = (string)$argumentName;
            $parameters["argumentValue$argumentIndex"] = $argumentValue;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        return (int)$connection->fetchOne($sql, $parameters);
    }

    /** @var string Redis key of the message id the debug-payload strip continues from (survives between cron runs). */
    public const string DEBUG_PAYLOAD_STRIP_CURSOR_CACHE_KEY = 'ai-request-snapshot-debug-payload-strip-cursor';

    /** @var int Snapshots shorter than this cannot carry a debug payload — the length test spares the JSON parse. */
    protected const int DEBUG_PAYLOAD_MIN_SNAPSHOT_BYTES = 4096;

    /** @var int Upper bound of the adaptive pause between two writes (milliseconds). */
    protected const int DEBUG_PAYLOAD_MAX_PAUSE_MS = 10000;

    /**
     * Remove the DEBUG payloads (`sentPayloadJson`, `rawResponseJson`) from request snapshots older than $olderThanDays,
     * politely: the slim snapshot stays (`sentAt`, `loopStepOverheadMs`, model, effort — `sentAt` is the point-in-time
     * key of the compaction cover), only the two debug keys go.
     *
     * Why it stays light on a 1.4 M-row / ~45 GB table that the turn worker writes all the time:
     * - it walks the PRIMARY KEY in windows of $windowSize ids (sequential clustered reads, no full-table JSON scan) from
     *   a cursor kept in Redis, up to the newest id older than the cutoff (found through the `created` index);
     * - only rows past a cheap LENGTH test are JSON-tested, and only rows that really carry a payload are written;
     * - every write is its own short transaction around one `UPDATE … WHERE id IN (…)` of at most $chunkSize rows, with a
     *   2-s wait for a row lock (a locked row is skipped, never waited on) and a 20-s statement cap
     *   ({@see beginBackgroundMaintenanceTransaction()});
     * - between writes it pauses $pauseMs, doubled while a write took longer than $slowWriteMs (a chunk rewrites up to
     *   $chunkSize × ~300 KB, so "slow" is its own threshold, never the pause); before each window it waits while the server
     *   is busy (Threads_running above $maxThreadsRunning) or the purge is behind (history list above
     *   $maxHistoryListLength — each stripped row leaves its old 300 KB in the undo log until the purge threads free it);
     * - it stops after $maxSeconds and the next run continues from the cursor. Rows are only ever older than the cutoff,
     *   so no running turn holds them.
     *
     * @param AIConversationProgressReporterInterface|null $progressReporter one line per window, for the command output
     * @return AIConversationRequestSnapshotDebugPayloadRemovalReport
     * @throws \Doctrine\DBAL\Exception
     */
    public function removeDebugPayloadsFromRequestSnapshots(
        int $olderThanDays,
        int $windowSize,
        int $chunkSize,
        int $pauseMs,
        int $slowWriteMs,
        int $maxSeconds,
        int $maxThreadsRunning,
        int $maxHistoryListLength,
        bool $dryRun,
        ?int $fromMessageId = null,
        ?AIConversationProgressReporterInterface $progressReporter = null
    ): AIConversationRequestSnapshotDebugPayloadRemovalReport {
        $startedAt = microtime(true);
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $tableName = DBAIConversationMessageModel::getTableName();
        $sqlDialect = EntityManagerFactory::getDialect();

        $cutoff = (new DateTime())->modify('-' . max(1, $olderThanDays) . ' days')->format('Y-m-d H:i:s');
        $cutoffMessageId = $connection->fetchOne(
            "SELECT `id` FROM `$tableName` WHERE `created` < :cutoff ORDER BY `created` DESC LIMIT 1",
            ['cutoff' => $cutoff]
        );
        $cutoffMessageId = $cutoffMessageId === false ? null : (int)$cutoffMessageId;

        // The cursor is a convenience: without Redis a run starts at $fromMessageId (or 0) and re-scans — the strip is
        // idempotent, so a lost cursor costs time, never correctness.
        $redisCache = null;
        $storedCursor = null;
        try {
            $redisCache = Cache::instance(Cache::CACHE_GROUP_REDIS);
            $storedCursor = $redisCache->get(self::DEBUG_PAYLOAD_STRIP_CURSOR_CACHE_KEY);
        } catch (\Throwable) {
            $redisCache = null;
        }
        $windowStartId = $fromMessageId ?? (is_numeric($storedCursor) ? (int)$storedCursor : 0);
        $debugPayloadRemovalReport = AIConversationRequestSnapshotDebugPayloadRemovalReport::forCutoffAndStartMessageId($cutoffMessageId, $windowStartId);
        if ($cutoffMessageId === null) {
            $debugPayloadRemovalReport->stoppedBecause = AIConversationRequestSnapshotDebugPayloadRemovalReport::STOPPED_BECAUSE_NO_MESSAGE_OLDER_THAN_THE_CUTOFF;

            return $debugPayloadRemovalReport;
        }

        $currentPauseMs = max(0, $pauseMs);
        $totalWriteMs = 0;
        // a key that is present has a type; one read of the document per key on both engines
        $rowCarriesDebugPayloadSql = '(' . $sqlDialect->jsonType('`requestSnapshot`', JsonPath::fromKeys('sentPayloadJson')) . ' IS NOT NULL'
            . ' OR ' . $sqlDialect->jsonType('`requestSnapshot`', JsonPath::fromKeys('rawResponseJson')) . ' IS NOT NULL)';
        $snapshotWithoutDebugPayloadsSql = $sqlDialect->jsonUpdate('`requestSnapshot`')
            ->remove(JsonPath::fromKeys('sentPayloadJson'))
            ->remove(JsonPath::fromKeys('rawResponseJson'))
            ->toSql();
        while ($windowStartId <= $cutoffMessageId) {
            if ((microtime(true) - $startedAt) >= $maxSeconds) {
                $debugPayloadRemovalReport->stoppedBecause = AIConversationRequestSnapshotDebugPayloadRemovalReport::STOPPED_BECAUSE_TIME_BUDGET_USED_UP;
                break;
            }
            if ($this->isDatabaseTooBusyForBackgroundWrites($maxThreadsRunning, $maxHistoryListLength)) {
                $debugPayloadRemovalReport->pausesForServerLoad++;
                usleep(5_000_000);
                continue;
            }
            $windowEndId = min($windowStartId + max(1, $windowSize) - 1, $cutoffMessageId);
            $messageIdsWithPayload = $this->findMessageIdsOfWindowMatching(
                $connection,
                "SELECT `id` FROM `$tableName` WHERE `id` BETWEEN :windowStartId AND :windowEndId"
                . ' AND `requestSnapshot` IS NOT NULL AND ' . static::buildRequestSnapshotByteLengthSql() . ' > :minimumBytes'
                . ' AND ' . $rowCarriesDebugPayloadSql,
                ['windowStartId' => $windowStartId, 'windowEndId' => $windowEndId, 'minimumBytes' => self::DEBUG_PAYLOAD_MIN_SNAPSHOT_BYTES]
            );
            $debugPayloadRemovalReport->windowsScanned++;
            $debugPayloadRemovalReport->rowsWithPayload += count($messageIdsWithPayload);

            foreach (array_chunk($messageIdsWithPayload, max(1, $chunkSize)) as $chunkMessageIds) {
                if ($dryRun) {
                    continue;
                }
                $writeStartedAt = microtime(true);
                $strippedRowCount = $this->writeBackgroundMaintenanceStatementUnlessRowsAreLocked(
                    $connection,
                    "UPDATE `$tableName` SET `requestSnapshot` = $snapshotWithoutDebugPayloadsSql"
                    . ' WHERE `id` IN (:chunkMessageIds) AND `created` < :cutoff',
                    ['chunkMessageIds' => $chunkMessageIds, 'cutoff' => $cutoff],
                    ['chunkMessageIds' => ArrayParameterType::INTEGER]
                );
                if ($strippedRowCount === null) {
                    $debugPayloadRemovalReport->chunksSkippedOnLock++;
                } else {
                    $debugPayloadRemovalReport->rowsStripped += $strippedRowCount;
                }
                $writeMs = (int)round((microtime(true) - $writeStartedAt) * 1000);
                $totalWriteMs += $writeMs;
                $debugPayloadRemovalReport->addWrite($writeMs, $totalWriteMs);
                // A slow write means the server is working hard: back off; a fast one lets the pause relax again.
                $currentPauseMs = $writeMs > max(1, $slowWriteMs)
                    ? min(self::DEBUG_PAYLOAD_MAX_PAUSE_MS, max(1, $currentPauseMs) * 2)
                    : max($pauseMs, intdiv($currentPauseMs, 2));
                usleep($currentPauseMs * 1000);
            }

            $debugPayloadRemovalReport->lastScannedMessageId = $windowEndId;
            $windowStartId = $windowEndId + 1;
            if (!$dryRun && $redisCache !== null) {
                try {
                    $redisCache->set(self::DEBUG_PAYLOAD_STRIP_CURSOR_CACHE_KEY, $windowStartId, 180 * 86400);
                } catch (\Throwable) {
                    $redisCache = null;
                }
            }
            if ($progressReporter !== null) {
                $progressReporter->reportProgress("ids {$debugPayloadRemovalReport->fromMessageId}–{$windowEndId} of {$cutoffMessageId}: {$debugPayloadRemovalReport->rowsWithPayload} with payload, {$debugPayloadRemovalReport->rowsStripped} stripped, average write {$debugPayloadRemovalReport->averageWriteMs} ms, pause {$currentPauseMs} ms");
            }
            // The window scan reads the snapshot column too — give the server the same breather after it.
            usleep($currentPauseMs * 1000);
        }

        return $debugPayloadRemovalReport;
    }

    /** @var int Seconds a background maintenance statement waits for a row lock before it gives the rows up. */
    protected const int BACKGROUND_MAINTENANCE_LOCK_WAIT_SECONDS = 2;

    /** @var int Seconds one background maintenance statement may run. */
    protected const int BACKGROUND_MAINTENANCE_STATEMENT_SECONDS = 20;

    /**
     * Begins a transaction of a background maintenance statement with its two limits: a short wait for a row lock and
     * a cap on the statement's run time. PostgreSQL holds the limits for this transaction only, MariaDB on the session
     * until {@see clearBackgroundMaintenanceLimits()}.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function beginBackgroundMaintenanceTransaction(Connection $connection): void
    {
        $connection->beginTransaction();
        $limitsAreSet = false;
        try {
            EntityManagerFactory::getDialect()->setTransactionTimeouts(
                $connection,
                self::BACKGROUND_MAINTENANCE_LOCK_WAIT_SECONDS,
                self::BACKGROUND_MAINTENANCE_STATEMENT_SECONDS
            );
            $limitsAreSet = true;
        } finally {
            if (!$limitsAreSet) {
                $connection->rollBack();
            }
        }
    }

    /**
     * Puts the session limits of {@see beginBackgroundMaintenanceTransaction()} back after a maintenance run (MariaDB;
     * on PostgreSQL they ended with each transaction), so a later job on the same connection waits for locks as usual.
     * A failure is ignored: the run's result stands.
     */
    protected function clearBackgroundMaintenanceLimits(Connection $connection): void
    {
        try {
            EntityManagerFactory::getDialect()->clearTransactionTimeouts($connection);
        } catch (\Throwable) {
        }
    }

    /**
     * Whether a statement failed because a row lock was not free within the limit (MariaDB error 1205, PostgreSQL
     * SQLSTATE 55P03 — the same exception on both engines).
     */
    protected function isRowLockWaitTimeout(\Throwable $statementError): bool
    {
        return $statementError instanceof LockWaitTimeoutException;
    }

    /**
     * One write of a background maintenance run in its own short transaction with the maintenance limits: the number
     * of rows it changed, or null when a row it needs was locked for longer than the limit (the caller skips the rows).
     *
     * @param array<string, mixed> $statementParameters
     * @param array<string, mixed> $statementParameterTypes
     * @throws \Doctrine\DBAL\Exception
     */
    protected function writeBackgroundMaintenanceStatementUnlessRowsAreLocked(
        Connection $connection,
        string $statementSql,
        array $statementParameters,
        array $statementParameterTypes
    ): ?int {
        $this->beginBackgroundMaintenanceTransaction($connection);
        try {
            $changedRowCount = (int)$connection->executeStatement($statementSql, $statementParameters, $statementParameterTypes);
            $connection->commit();
            return $changedRowCount;
        } catch (\Throwable $statementError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($this->isRowLockWaitTimeout($statementError)) {
                return null;
            }
            throw $statementError;
        } finally {
            $this->clearBackgroundMaintenanceLimits($connection);
        }
    }

    /**
     * The ids one window scan of a background maintenance run selects, read under the maintenance limits (the scan
     * reads large columns; the statement cap bounds it).
     *
     * @param array<string, mixed> $statementParameters
     * @return int[]
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findMessageIdsOfWindowMatching(Connection $connection, string $statementSql, array $statementParameters): array
    {
        $this->beginBackgroundMaintenanceTransaction($connection);
        try {
            $messageIds = array_map('intval', $connection->fetchFirstColumn($statementSql, $statementParameters));
            $connection->commit();
            return $messageIds;
        } catch (\Throwable $statementError) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $statementError;
        } finally {
            $this->clearBackgroundMaintenanceLimits($connection);
        }
    }

    /**
     * The byte length of a row's request snapshot as SQL — the cheap test that spares the JSON read of a snapshot too
     * short to carry a debug payload. MariaDB stores the document as text; PostgreSQL measures its text form.
     */
    protected static function buildRequestSnapshotByteLengthSql(): string
    {
        return EntityManagerFactory::getDialect()->capabilities()->isPostgres()
            ? 'OCTET_LENGTH(CAST(`requestSnapshot` AS text))'
            : 'LENGTH(`requestSnapshot`)';
    }

    /** @var string Redis key of the conversation cursor of {@see compactLoadedCapabilities()} — the next run continues there. */
    public const string LOADED_CAPABILITIES_COMPACT_CURSOR_CACHE_KEY = 'ai-loaded-capabilities-compact-cursor';

    /**
     * Rewrite the legacy capability rows (each carrying its complete set) into the chain format — one
     * conversation at a time, politely: conversations by id from a Redis cursor, a run in progress or a conversation
     * with a message younger than $quietMinutes is skipped, every write block is one short transaction that first
     * locks its rows and compares their stored sets with what was read (a row the live writer touched in between
     * aborts the block, the conversation is retried once, then skipped), a 2-s wait for a row lock, the adaptive
     * pause and the busy-server guard of {@see removeDebugPayloadsFromRequestSnapshots()}, a time budget.
     *
     * @param AIConversationProgressReporterInterface|null $progressReporter one line per conversation batch, for the command output
     * @return AIConversationLoadedCapabilitiesRewriteReport
     * @throws \Doctrine\DBAL\Exception
     */
    public function compactLoadedCapabilities(
        int $batchSize,
        int $pauseMs,
        int $slowWriteMs,
        int $maxSeconds,
        int $maxThreadsRunning,
        int $quietMinutes,
        bool $dryRun,
        ?int $fromConversationId = null,
        ?int $onlyConversationId = null,
        ?AIConversationProgressReporterInterface $progressReporter = null,
        bool $verify = false
    ): AIConversationLoadedCapabilitiesRewriteReport {
        $startedAt = microtime(true);
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationTableName = DBAIConversationModel::getTableName();
        $redisCache = null;
        $storedCursor = null;
        if ($onlyConversationId === null) {
            try {
                $redisCache = Cache::instance(Cache::CACHE_GROUP_REDIS);
                $storedCursor = $redisCache->get(self::LOADED_CAPABILITIES_COMPACT_CURSOR_CACHE_KEY);
            } catch (\Throwable) {
                $redisCache = null;
            }
        }
        $cursor = $fromConversationId ?? (is_numeric($storedCursor) ? (int)$storedCursor : 0);
        $loadedCapabilitiesRewriteReport = AIConversationLoadedCapabilitiesRewriteReport::forStartConversationId($cursor);
        $currentPauseMs = max(0, $pauseMs);
        while (true) {
            if ((microtime(true) - $startedAt) >= $maxSeconds) {
                $loadedCapabilitiesRewriteReport->stoppedBecause = AIConversationLoadedCapabilitiesRewriteReport::STOPPED_BECAUSE_TIME_BUDGET_USED_UP;
                break;
            }
            if ($this->isDatabaseTooBusyForBackgroundWrites($maxThreadsRunning, PHP_INT_MAX)) {
                $loadedCapabilitiesRewriteReport->pausesForServerLoad++;
                usleep(5_000_000);
                continue;
            }
            $conversationIds = $onlyConversationId !== null
                ? ($loadedCapabilitiesRewriteReport->lastConversationId === null ? [$onlyConversationId] : [])
                : array_map('intval', $connection->fetchFirstColumn(
                    "SELECT `id` FROM `$conversationTableName` WHERE `id` > :cursor ORDER BY `id` LIMIT " . max(1, $batchSize),
                    ['cursor' => $cursor]
                ));
            if ($conversationIds === []) {
                break;
            }
            foreach ($conversationIds as $conversationId) {
                if ((microtime(true) - $startedAt) >= $maxSeconds) {
                    $loadedCapabilitiesRewriteReport->stoppedBecause = AIConversationLoadedCapabilitiesRewriteReport::STOPPED_BECAUSE_TIME_BUDGET_USED_UP;
                    break 2;
                }
                $rewriteOutcome = $this->compactLoadedCapabilitiesOfConversation($conversationId, $quietMinutes, $dryRun, $verify);
                if ($rewriteOutcome->isConflict()) {
                    $rewriteOutcome = $this->compactLoadedCapabilitiesOfConversation($conversationId, $quietMinutes, $dryRun, $verify);
                }
                $loadedCapabilitiesRewriteReport->addOutcomeOfConversation($conversationId, $rewriteOutcome);
                $cursor = $conversationId;
                if ($rewriteOutcome->rowsRewritten > 0 && !$dryRun) {
                    $currentPauseMs = $rewriteOutcome->slowestWriteMs > max(1, $slowWriteMs)
                        ? min(self::DEBUG_PAYLOAD_MAX_PAUSE_MS, max(1, $currentPauseMs) * 2)
                        : max($pauseMs, intdiv($currentPauseMs, 2));
                    usleep($currentPauseMs * 1000);
                }
            }
            if (!$dryRun && $redisCache !== null) {
                try {
                    $redisCache->set(self::LOADED_CAPABILITIES_COMPACT_CURSOR_CACHE_KEY, $cursor, 180 * 86400);
                } catch (\Throwable) {
                    $redisCache = null;
                }
            }
            if ($progressReporter !== null) {
                $progressReporter->reportProgress("conversations up to {$cursor}: {$loadedCapabilitiesRewriteReport->conversationsCompacted} compacted, {$loadedCapabilitiesRewriteReport->rowsRewritten} rows, "
                    . $loadedCapabilitiesRewriteReport->getSavedMegabytes() . " MB saved, pause {$currentPauseMs} ms");
            }
        }
        return $loadedCapabilitiesRewriteReport;
    }

    /**
     * One conversation of {@see compactLoadedCapabilities()}: read its rows, re-encode them
     * ({@see LoadedCapabilitiesChain::compactStoredRows()}), write them block by block — each block (a checkpoint and
     * the change rows after it) in one transaction that locks the block's rows and compares their stored sets first.
     *
     * With `$verify`, the conversation is loaded afterwards through the hydrating loader and every row's resolved set is
     * compared with the complete set it stored before.
     *
     * @param int $conversationId
     * @param int $quietMinutes
     * @param bool $dryRun
     * @param bool $verify
     * @return AIConversationLoadedCapabilitiesRewriteOutcome
     * @throws \Doctrine\DBAL\Exception
     */
    public function compactLoadedCapabilitiesOfConversation(int $conversationId, int $quietMinutes, bool $dryRun, bool $verify = false): AIConversationLoadedCapabilitiesRewriteOutcome
    {
        $rewriteOutcome = new AIConversationLoadedCapabilitiesRewriteOutcome();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $messageTableName = DBAIConversationMessageModel::getTableName();
        $conversationTableName = DBAIConversationModel::getTableName();
        $conversationState = $connection->fetchAssociative(
            "SELECT `conversation`.`runState`, (SELECT MAX(`message`.`created`) FROM `$messageTableName` `message`"
            . ' WHERE `message`.`aiConversationId` = `conversation`.`id`) AS `newestMessageAt`'
            . " FROM `$conversationTableName` `conversation` WHERE `conversation`.`id` = :conversationId",
            ['conversationId' => $conversationId]
        );
        if ($conversationState === false) {
            return $rewriteOutcome;
        }
        $quietBefore = (new DateTime())->modify('-' . max(0, $quietMinutes) . ' minutes')->format('Y-m-d H:i:s');
        if (AIConversationRunState::of($conversationState['runState'])->holdsALiveTurn()
            || ($conversationState['newestMessageAt'] !== null && $conversationState['newestMessageAt'] > $quietBefore)) {
            $rewriteOutcome->outcome = AIConversationLoadedCapabilitiesRewriteOutcome::OUTCOME_ACTIVE;
            return $rewriteOutcome;
        }
        $storedRows = $connection->fetchAllAssociative(
            "SELECT `id`, `indexInConversation`, `role`, `authorType`, `messageType`, `loadedCapabilities` FROM `$messageTableName`"
            . ' WHERE `aiConversationId` = :conversationId AND `truncatedAt` IS NULL ORDER BY `indexInConversation`',
            ['conversationId' => $conversationId]
        );
        $chainRows = [];
        $readLoadedCapabilitiesById = [];
        $lengthById = [];
        $predecessorIdById = [];
        $previousSetRowId = null;
        foreach ($storedRows as $storedRow) {
            if ($storedRow['loadedCapabilities'] !== null) {
                $predecessorIdById[(int)$storedRow['id']] = $previousSetRowId;
                $previousSetRowId = (int)$storedRow['id'];
            }
            $chainRows[] = LoadedCapabilitiesStoredRow::create(
                (int)$storedRow['id'],
                // the row twin of AIConversationMessage::isOwnerOrLegacyUserRoleRow(): the owner's, a legacy row, or a
                // delegation child's task (the AUTHOR_AGENT TEXT at index 0)
                $storedRow['role'] === AIConversationMessage::ROLE_USER
                    && ($storedRow['authorType'] === AIConversationMessage::AUTHOR_USER || $storedRow['authorType'] === null
                        || ($storedRow['authorType'] === AIConversationMessage::AUTHOR_AGENT && $storedRow['messageType'] === AIConversationMessage::MESSAGE_TEXT
                            && (int)$storedRow['indexInConversation'] === 0)),
                $storedRow['loadedCapabilities']
            );
            $readLoadedCapabilitiesById[(int)$storedRow['id']] = $storedRow['loadedCapabilities'] === null ? null : (string)$storedRow['loadedCapabilities'];
            $lengthById[(int)$storedRow['id']] = strlen((string)($storedRow['loadedCapabilities'] ?? ''));
        }
        $rowsToWrite = LoadedCapabilitiesChain::compactStoredRows($chainRows);
        foreach ($rowsToWrite as $rowToWrite) {
            $rewriteOutcome->bytesBefore += $lengthById[$rowToWrite->id];
            $rewriteOutcome->bytesAfter += strlen($rowToWrite->loadedCapabilities);
        }
        if ($dryRun || $rowsToWrite === []) {
            $rewriteOutcome->rowsRewritten = count($rowsToWrite);
            return $rewriteOutcome;
        }
        foreach (array_chunk($rowsToWrite, LoadedCapabilitiesChain::MAX_ROWS_BETWEEN_CHECKPOINTS) as $block) {
            $blockIds = array_map(static fn (LoadedCapabilitiesRowRewrite $rowToWrite): int => $rowToWrite->id, $block);
            // the predecessor of the block's first row is the base its changes were computed against — lock and compare it too
            $lockedIds = array_values(array_filter(array_unique(array_merge([$predecessorIdById[$blockIds[0]] ?? null], $blockIds))));
            $writeStartedAt = microtime(true);
            $this->beginBackgroundMaintenanceTransaction($connection);
            try {
                // the stored sets themselves are compared, byte for byte: no checksum function both engines share
                $lockedLoadedCapabilitiesById = $connection->fetchAllKeyValue(
                    "SELECT `id`, `loadedCapabilities` FROM `$messageTableName` WHERE `id` IN (:lockedIds) FOR UPDATE",
                    ['lockedIds' => $lockedIds],
                    ['lockedIds' => ArrayParameterType::INTEGER]
                );
                foreach ($lockedIds as $blockId) {
                    $lockedLoadedCapabilities = $lockedLoadedCapabilitiesById[$blockId] ?? null;
                    if (($lockedLoadedCapabilities === null ? null : (string)$lockedLoadedCapabilities) !== $readLoadedCapabilitiesById[$blockId]) {
                        $connection->rollBack();
                        $rewriteOutcome->outcome = AIConversationLoadedCapabilitiesRewriteOutcome::OUTCOME_CONFLICT;
                        return $rewriteOutcome;
                    }
                }
                $caseSql = '';
                $parameters = ['blockIds' => $blockIds];
                foreach ($block as $position => $rowToWrite) {
                    $caseSql .= " WHEN :id{$position} THEN :changes{$position}";
                    $parameters["id{$position}"] = $rowToWrite->id;
                    $parameters["changes{$position}"] = $rowToWrite->loadedCapabilities;
                }
                $connection->executeStatement(
                    "UPDATE `$messageTableName` SET `loadedCapabilities` = CASE `id`{$caseSql} END WHERE `id` IN (:blockIds)",
                    $parameters,
                    ['blockIds' => ArrayParameterType::INTEGER]
                );
                $connection->commit();
            } catch (\Throwable $throwable) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                if ($this->isRowLockWaitTimeout($throwable)) {
                    $rewriteOutcome->outcome = AIConversationLoadedCapabilitiesRewriteOutcome::OUTCOME_CONFLICT;
                    return $rewriteOutcome;
                }
                throw $throwable;
            } finally {
                $this->clearBackgroundMaintenanceLimits($connection);
            }
            $rewriteOutcome->rowsRewritten += count($block);
            $rewriteOutcome->slowestWriteMs = max($rewriteOutcome->slowestWriteMs, (int)round((microtime(true) - $writeStartedAt) * 1000));
        }
        if ($verify) {
            $legacyCanonicalById = [];
            foreach ($chainRows as $chainRow) {
                if ($chainRow->loadedCapabilities === null) {
                    continue;
                }
                $legacySet = new LoadedCapabilities();
                $legacySet->mapFromRepository($chainRow->loadedCapabilities);
                if (!$legacySet->isChangeRecord()) {
                    $legacyCanonicalById[$chainRow->id] = $legacySet->toCanonicalEntries();
                }
            }
            $conversation = AIConversation::getService()->find($conversationId);
            foreach ($this->findAllMessagesForConversationInOrder($conversation, useEntityRegistrCache: false)?->getElements() ?? [] as $reloadedRow) {
                $expectedCanonical = $legacyCanonicalById[(int)($reloadedRow->id ?? 0)] ?? null;
                if ($expectedCanonical === null) {
                    continue;
                }
                $rewriteOutcome->verifiedRows++;
                $resolvedCanonical = $reloadedRow->getLoadedCapabilities()?->toCanonicalEntries();
                if ($resolvedCanonical !== $expectedCanonical) {
                    $rewriteOutcome->verifyMismatches++;
                }
            }
        }
        return $rewriteOutcome;
    }

    /**
     * Whether a background write should wait: more statements are executing right now than $maxThreadsRunning, or the
     * InnoDB purge lags behind (history list length above $maxHistoryListLength). A failed read of either counts as
     * "not busy" — the per-write lock timeout and the pause still bound the impact. Both counters are MariaDB's; on
     * another engine the answer is "not busy" and the same two bounds apply.
     */
    public function isDatabaseTooBusyForBackgroundWrites(int $maxThreadsRunning, int $maxHistoryListLength): bool
    {
        if (!EntityManagerFactory::getDialect()->capabilities()->isMariaDb()) {
            return false;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        try {
            $threadsRunning = (int)$connection->fetchOne(
                "SELECT `VARIABLE_VALUE` FROM `information_schema`.`GLOBAL_STATUS` WHERE `VARIABLE_NAME` = 'THREADS_RUNNING'"
            );
            if ($threadsRunning > $maxThreadsRunning) {
                return true;
            }
            $historyListLength = $connection->fetchOne(
                "SELECT `COUNT` FROM `information_schema`.`INNODB_METRICS` WHERE `NAME` = 'trx_rseg_history_len'"
            );

            return $historyListLength !== false && (int)$historyListLength > $maxHistoryListLength;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Copy the persisted `delegationResults` and call status of a `delegate` call row onto the conversation's loaded copy
     * of that row (the request to the model renders the loaded set). Read past rights and the entity registry: the
     * children wrote the row from other processes. False when the row cannot be read or is not in the loaded set.
     */
    public function refreshDelegateCallRowInLoadedMessages(AIConversation $conversation, int $callMessageId): bool
    {
        if (!isset($conversation->messages)) {
            return false;
        }
        try {
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $freshRow = $this->find($callMessageId, useEntityRegistrCache: false);
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        } catch (\Throwable) {
            return false;
        }
        if (!$freshRow instanceof AIConversationMessage) {
            return false;
        }
        foreach ($conversation->messages->getElements() as $loadedMessage) {
            if ((int)($loadedMessage->id ?? 0) !== $callMessageId) {
                continue;
            }
            if (isset($freshRow->delegationResults)) {
                $loadedMessage->delegationResults = $freshRow->delegationResults;
            }
            if (isset($loadedMessage->toolCall) && isset($freshRow->toolCall) && ($freshRow->toolCall->status ?? null) !== null) {
                $loadedMessage->toolCall->status = $freshRow->toolCall->status;
                $loadedMessage->toolCall->result = $freshRow->toolCall->result ?? $loadedMessage->toolCall->result;
            }
            return true;
        }
        return false;
    }

    /**
     * The id of the conversation's newest TOOL CALL row named one of `$toolNames` above `$afterMessageId` (any status),
     * or null — one MAX() over the conversation's rows past the id. A purpose that hands owner input to a chat reads
     * it: a chat that called one of the tools after the signal and started no work was refused (the tools answer a
     * refusal as a COMPLETED result), so its reply is no answer to the signal.
     *
     * @param int $aiConversationId the conversation whose rows are read
     * @param int $afterMessageId only rows with a higher id count
     * @param string[] $toolNames the tool names as persisted in `toolCall.name`
     * @return int|null
     * @throws \Doctrine\DBAL\Exception
     */
    public function findNewestToolCallMessageIdOfNamesAfter(int $aiConversationId, int $afterMessageId, array $toolNames): ?int
    {
        if ($toolNames === []) {
            return null;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $newestMessageId = $connection->fetchOne(
            'SELECT MAX(`id`) FROM `' . DBAIConversationMessageModel::getTableName() . '`'
            . ' WHERE `aiConversationId` = :conversationId AND `id` > :afterMessageId AND `virtualToolName` IN (:toolNames)',
            ['conversationId' => $aiConversationId, 'afterMessageId' => $afterMessageId, 'toolNames' => array_values($toolNames)],
            ['toolNames' => ArrayParameterType::STRING]
        );
        return $newestMessageId === false || $newestMessageId === null ? null : (int)$newestMessageId;
    }

    /**
     * A capability change of a row the turn already holds — the cleanup, the eviction sweep, the skill unloading: ONLY
     * the capability column is written ({@see persistLoadedCapabilitiesUnderRunClaim()}, the chain re-encode included), never the
     * whole row, so a column another process set meanwhile stays (the screening worker's `toolCall` verdict on the
     * previous step's call row — a full re-write put back the un-screened result). A row not persisted yet takes the
     * normal persist.
     */
    public function persistCapabilityChangeOfRowUnderRun(AgentRun $agentRun, AIConversation $conversation, AIConversationMessage $message, ?AgentConsoleStream $consoleStream, bool $emitRealtimeSignal): void
    {
        if (!isset($conversation->id) || !isset($message->id)) {
            $this->persistMessageUnderRun($agentRun, $message, $consoleStream, $emitRealtimeSignal);
            return;
        }
        // the claim fence lives in the write itself — a worker that lost the claim writes nothing
        $this->persistLoadedCapabilitiesUnderRunClaim($conversation, $message, $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation));
    }

    /**
     * The SPECULATIVE CLEANUP call — its own pass at the top of every step, BEFORE the tool schemas are
     * built and OUTSIDE the retention bypass: a decision-model preload that saw no use in the turn it was loaded for
     * leaves now (turns counted by initiator index), detached from every tool that listed it. Persists the anchor.
     */
    public function cleanupUnusedSpeculativeCapabilitiesOfRun(AgentRun $agentRun, AIConversation $conversation, ?AgentConsoleStream $consoleStream = null, bool $emitRealtimeSignal = false): void
    {
        // nothing is unloaded inside an append-only epoch — an unused speculative preload stays loaded
        if ($conversation->usesConversationAppend()) {
            $this->aiTrace('turn.eviction.skipped', ['conversationId' => $conversation->id ?? null, 'reason' => 'conversationAppend', 'pass' => 'speculativeCleanup']);
            return;
        }
        // the anchor is the newest message that CARRIES a set — at a turn start `last()` is the owner's
        // initiator row without one, and anchoring on it skipped the whole turn.
        $anchorMessage = $conversation->messages->getNewestMessageWithLoadedCapabilities();
        $anchorLoadedCapabilities = $anchorMessage?->getLoadedCapabilities();
        if ($anchorLoadedCapabilities === null) {
            return;
        }
        $currentInitiatorIndex = $conversation->messages->getNewestInitiatorMessageIndex();
        if ($currentInitiatorIndex === null) {
            return;
        }
        // under SKILL-granular unloading a preload leaves only as a WHOLE unused group — a preloaded tool
        // whose sibling was used stays with its live skill; under `tool` the per-tool rule.
        $removedNames = $conversation->unloadsPerSkill()
            ? $anchorLoadedCapabilities->removeSpeculativeUnusedGroups(
                $currentInitiatorIndex,
                LoadedSkillGroups::buildFor($anchorLoadedCapabilities, $anchorMessage->findLoadedAISkills())
            )
            : $anchorLoadedCapabilities->removeSpeculativeUnused($currentInitiatorIndex);
        if ($removedNames === []) {
            return;
        }
        $this->persistCapabilityChangeOfRowUnderRun($agentRun, $conversation, $anchorMessage, $consoleStream, $emitRealtimeSignal);
        $this->aiTrace('turn.preload.cleanup', ['conversationId' => $conversation->id ?? null, 'removed' => $removedNames]);
    }

    /**
     * The per-turn unloading of cold resident capabilities: the decision and the change
     * of the anchor's set are {@see AgentColdCapabilityUnloading}; this persists the anchor's capability column once and
     * records one always-on `eviction` row per victim AFTER the persist (a crash in between would otherwise leave a
     * phantom row) — the speculative preloads removed first are recorded as they go, as before.
     */
    public function unloadColdCapabilitiesOfRun(AgentRun $agentRun, AIConversation $conversation, ?AgentConsoleStream $consoleStream = null, bool $emitRealtimeSignal = false): void
    {
        // nothing is unloaded inside an append-only epoch (the knobs are inert; the compaction rebase is
        // the only moment the set is rebuilt)
        if ($conversation->usesConversationAppend()) {
            $this->aiTrace('turn.eviction.skipped', ['conversationId' => $conversation->id ?? null, 'reason' => 'conversationAppend', 'pass' => 'coldUnloading']);
            return;
        }
        $unloading = AgentColdCapabilityUnloading::applyTo($conversation);
        if ($unloading === null || !$unloading->changedTheSet()) {
            return;
        }
        $runToken = isset($conversation->runToken) ? $conversation->runToken : null;
        $initiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex();
        if ($unloading->speculativeFirstNames !== []) {
            $this->aiTrace('turn.eviction.speculativeFirst', ['conversationId' => $conversation->id ?? null, 'evicted' => $unloading->speculativeFirstNames]);
            foreach ($unloading->speculativeFirstNames as $speculativeVictimName) {
                $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_EVICTION, null, $initiatorMessageIndex, $speculativeVictimName, null, ['evictedBy' => 'speculative'], $runToken);
            }
        }
        $this->persistCapabilityChangeOfRowUnderRun($agentRun, $conversation, $unloading->anchorMessage, $consoleStream, $emitRealtimeSignal);
        if ($unloading->priorTelemetryByVictimName === []) {
            return;
        }
        $victimNames = array_map('strval', array_keys($unloading->priorTelemetryByVictimName));
        $this->aiTrace('turn.eviction', $unloading->granularity === ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL
            ? ['conversationId' => $conversation->id ?? null, 'granularity' => ContextRetentionSettings::UNLOAD_GRANULARITY_SKILL, 'evicted' => $victimNames]
            : ['conversationId' => $conversation->id ?? null, 'evicted' => $victimNames]);
        foreach ($unloading->priorTelemetryByVictimName as $victimName => $priorTelemetry) {
            $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_EVICTION, null, $initiatorMessageIndex, (string)$victimName, null, [
                'evictedBy' => $unloading->getEvictedBy(),
                'granularity' => $unloading->granularity,
                'prior' => $priorTelemetry,
            ], $runToken);
        }
    }

    /** The turn events (the eviction rows of a capability change) — resolved inline (DI rule). */
    protected function turnEventsService(): AIConversationTurnEventsService
    {
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        return $aIConversationTurnEventsService;
    }
}
