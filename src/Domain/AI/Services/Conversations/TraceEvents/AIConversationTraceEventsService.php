<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\TraceEvents;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvents;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationMessageWriter;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationMessageWriters;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEventModel;
use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEvents;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Throwable;

/**
 * The ONE writer of the conversation trace table and its readers. Every write is
 *   - OFF the turn path: only the voice sideband workers, the voice controller and the `deviceStatus` endpoint call
 *     it — never the turn worker (the turn must not get slower);
 *   - BATCHED: {@see recordEvents()} writes any number of events as ONE multi-row statement, so a voice worker pays
 *     one round trip per 2-s tick, not one per event;
 *   - IDEMPOTENT: an insert that skips an existing unique {@see AIConversationTraceEvent::$eventKey} (the dialect's
 *     `insertSkippingExistingRows()`, which reports the rows written on both engines);
 *   - FAIL-OPEN: a missing table, a refused insert, a broken connection are logged and swallowed — tracing never
 *     blocks a call, a message write or a turn.
 * Raw SQL by design (as the turn events): the entity class feeds `app:entity:show-sql` (the owner applies the DDL)
 * and the inspector's rights-scoped reads; a trace row is never an entity update.
 *
 * @method AIConversationTraceEvent find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 */
class AIConversationTraceEventsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AIConversationTraceEvent::class;

    /** The retention of the table — its own batched purge ({@see purgeOlderThanDays()}). */
    public const int RETENTION_DAYS = 180;

    /** Rows per INSERT statement; a larger batch is split. */
    public const int ROWS_PER_STATEMENT = 200;

    /** The persisted columns, in statement order. */
    protected const array COLUMNS = [
        'aiConversationId', 'agentVoiceCallId', 'messageIndex', 'occurredAtUnixMs', 'receivedAtUnixMs',
        'providerOffsetMs', 'source', 'eventType', 'eventKey', 'durationMs', 'summary', 'payload', 'truncated',
        'originalLength', 'created', 'updated',
    ];

    /**
     * Record one event. Fail-open.
     *
     * @param AIConversationTraceEvent $traceEvent
     * @return bool True when it was inserted (false on a duplicate or a failure)
     */
    public function recordEvent(AIConversationTraceEvent $traceEvent): bool
    {
        return $this->recordEvents([$traceEvent]) === 1;
    }

    /**
     * Record events as multi-row statements (one per {@see ROWS_PER_STATEMENT}). FAIL-OPEN: a failed statement is
     * logged and its events are dropped — the caller never sees an exception, so a voice worker can call this on
     * every tick without a guard of its own.
     *
     * @param AIConversationTraceEvent[] $traceEvents
     * @return int How many rows were newly inserted
     */
    public function recordEvents(array $traceEvents): int
    {
        if ($traceEvents === []) {
            return 0;
        }
        $insertedRows = 0;
        try {
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $eventsTable = DBAIConversationTraceEventModel::getTableName();
            $columnList = implode(', ', array_map(static fn(string $column): string => "`$column`", self::COLUMNS));
            $connection = EntityManagerFactory::getInstance()->getConnection();
            $sqlDialect = EntityManagerFactory::getDialect();
        } catch (Throwable $failure) {
            $this->logRecordFailure($traceEvents, $failure);

            return 0;
        }
        foreach (array_chunk(array_values($traceEvents), self::ROWS_PER_STATEMENT) as $chunk) {
            try {
                $rowPlaceholders = [];
                $parameters = [];
                foreach ($chunk as $rowNumber => $traceEvent) {
                    $values = [
                        'aiConversationId' => $traceEvent->aiConversationId ?? null,
                        'agentVoiceCallId' => $traceEvent->agentVoiceCallId ?? null,
                        'messageIndex' => $traceEvent->messageIndex ?? null,
                        'occurredAtUnixMs' => (int)($traceEvent->occurredAtUnixMs ?? AIConversationTraceEvent::nowUnixMs()),
                        'receivedAtUnixMs' => $traceEvent->receivedAtUnixMs ?? null,
                        'providerOffsetMs' => $traceEvent->providerOffsetMs ?? null,
                        'source' => (string)$traceEvent->source,
                        'eventType' => (string)$traceEvent->eventType,
                        'eventKey' => (string)$traceEvent->eventKey,
                        'durationMs' => $traceEvent->durationMs ?? null,
                        'summary' => $traceEvent->summary ?? null,
                        'payload' => $traceEvent->payload ?? null,
                        'truncated' => (int)($traceEvent->truncated ?? false),
                        'originalLength' => $traceEvent->originalLength ?? null,
                        'created' => $now,
                        'updated' => $now,
                    ];
                    $placeholders = [];
                    foreach ($values as $column => $value) {
                        $placeholder = "{$column}_{$rowNumber}";
                        $placeholders[] = ":$placeholder";
                        $parameters[$placeholder] = $value;
                    }
                    $rowPlaceholders[] = '(' . implode(', ', $placeholders) . ')';
                }
                $sql = $sqlDialect->insertSkippingExistingRows(
                    "INSERT INTO `$eventsTable` ($columnList) VALUES " . implode(', ', $rowPlaceholders),
                    $eventsTable,
                    'eventKey'
                );
                // 1 per inserted row and 0 per duplicate, on both engines
                $insertedRows += (int)$connection->executeStatement($sql, $parameters);
            } catch (Throwable $failure) {
                $this->logRecordFailure($chunk, $failure);
            }
        }

        return $insertedRows;
    }

    /**
     * Link a call's events that were written before the call had a conversation (setup events) to it. Fail-open.
     *
     * @param int $agentVoiceCallId
     * @param int $aiConversationId
     * @return void
     */
    public function attachCallEventsToConversation(int $agentVoiceCallId, int $aiConversationId): void
    {
        try {
            EntityManagerFactory::getInstance()->getConnection()->executeStatement(
                'UPDATE `' . DBAIConversationTraceEventModel::getTableName() . '` SET `aiConversationId` = :conversationId'
                . ' WHERE `agentVoiceCallId` = :callId AND `aiConversationId` IS NULL',
                ['conversationId' => $aiConversationId, 'callId' => $agentVoiceCallId]
            );
        } catch (Throwable $failure) {
            DDDService::instance()->getLogger()->warning('[AIConversationTraceEvents] call events not linked', [
                'agentVoiceCallId' => $agentVoiceCallId, 'reason' => $failure::class,
            ]);
        }
    }

    /**
     * The events of a conversation in time order — the inspector's read; rights-scoped through the repo.
     *
     * @param int $conversationId
     * @param int $limit
     * @return AIConversationTraceEvents|null
     */
    public function findForConversation(int $conversationId, int $limit = 5000): ?AIConversationTraceEvents
    {
        /** @var DBAIConversationTraceEvents $repo */
        $repo = AIConversationTraceEvents::getRepoClassInstance();

        return $repo->findForConversation($conversationId, $limit);
    }

    /**
     * The writer of every row of one inspector page: one provenance query for the page's index range,
     * then the ordered classifier per row. Read side only — nothing here touches the turn path.
     *
     * @param AIConversation $aiConversation
     * @param AIConversationMessages $pageMessages
     * @return AIConversationMessageWriters
     */
    public function buildMessageWritersForPage(AIConversation $aiConversation, AIConversationMessages $pageMessages): AIConversationMessageWriters
    {
        $messageWriters = new AIConversationMessageWriters();
        $pageIndices = [];
        foreach ($pageMessages->getElements() as $pageMessage) {
            if (isset($pageMessage->indexInConversation)) {
                $pageIndices[] = (int)$pageMessage->indexInConversation;
            }
        }
        $provenanceByMessageIndex = [];
        if ($pageIndices !== [] && $aiConversation->id !== null) {
            try {
                /** @var DBAIConversationTraceEvents $repo */
                $repo = AIConversationTraceEvents::getRepoClassInstance();
                $provenanceEvents = $repo->findProvenanceForMessageIndexRange((int)$aiConversation->id, min($pageIndices), max($pageIndices));
                foreach ($provenanceEvents?->getElements() ?? [] as $provenanceEvent) {
                    $provenanceByMessageIndex[(int)$provenanceEvent->messageIndex] = $provenanceEvent;
                }
            } catch (Throwable $provenanceReadFailure) {
                // No trace table yet (DDL pending) — every row is classified by the heuristic alone.
                DDDService::instance()->getLogger()->info('[AIConversationTraceEvents] provenance not readable', [
                    'reason' => $provenanceReadFailure::class,
                ]);
            }
        }
        $conversationHasVoiceCalls = false;
        $callTaskTexts = [];
        if ($aiConversation->id !== null) {
            /** @var AgentVoiceChannelService $agentVoiceChannelService */
            $agentVoiceChannelService = DDDService::instance()->getService(AgentVoiceChannelService::class);
            $agentCallTaskTexts = $agentVoiceChannelService->findCallTaskTextsOfConversation($aiConversation);
            $conversationHasVoiceCalls = $agentCallTaskTexts->hasCalls;
            $callTaskTexts = $agentCallTaskTexts->taskTexts;
        }
        foreach ($pageMessages->getElements() as $pageMessage) {
            $messageIndex = isset($pageMessage->indexInConversation) ? (int)$pageMessage->indexInConversation : null;
            $messageWriter = AIConversationMessageWriter::classify(
                $pageMessage,
                $messageIndex !== null ? ($provenanceByMessageIndex[$messageIndex] ?? null) : null,
                $conversationHasVoiceCalls,
                $callTaskTexts
            );
            $messageWriters->add($messageWriter);
        }

        return $messageWriters;
    }

    /**
     * The 180-day retention purge, batched on the `created` index (a batch reads at most `$batchSize` expired ids and
     * deletes exactly those rows — PostgreSQL has no `DELETE … LIMIT`) — the table's own lifecycle;
     * it also removes call events that were never linked to a conversation.
     *
     * @param int $days
     * @param int $batchSize
     * @param int $maxBatches
     * @return int The rows deleted
     */
    public function purgeOlderThanDays(int $days = self::RETENTION_DAYS, int $batchSize = 5000, int $maxBatches = 200): int
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $eventsTable = DBAIConversationTraceEventModel::getTableName();
        $cutoff = (new DateTime())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $deleted = 0;
        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $expiredEventIds = array_map('intval', $connection->fetchFirstColumn(
                "SELECT `id` FROM `$eventsTable` WHERE `created` < :cutoff LIMIT " . max(1, $batchSize),
                ['cutoff' => $cutoff]
            ));
            if ($expiredEventIds === []) {
                break;
            }
            $affected = (int)$connection->executeStatement(
                "DELETE FROM `$eventsTable` WHERE `id` IN (:expiredEventIds) AND `created` < :cutoff",
                ['expiredEventIds' => $expiredEventIds, 'cutoff' => $cutoff],
                ['expiredEventIds' => ArrayParameterType::INTEGER]
            );
            $deleted += $affected;
            if ($affected < $batchSize) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * Log a failed write without the payloads (they can carry transcript text).
     *
     * @param AIConversationTraceEvent[] $traceEvents
     * @param Throwable $failure
     * @return void
     */
    protected function logRecordFailure(array $traceEvents, Throwable $failure): void
    {
        $firstTraceEvent = $traceEvents[array_key_first($traceEvents)] ?? null;
        DDDService::instance()->getLogger()->warning('[AIConversationTraceEvents] events not recorded', [
            'events' => count($traceEvents),
            'firstEventType' => $firstTraceEvent?->eventType ?? null,
            'agentVoiceCallId' => $firstTraceEvent?->agentVoiceCallId ?? null,
            'reason' => $failure::class . ': ' . mb_substr($failure->getMessage(), 0, 200),
        ]);
    }
}
