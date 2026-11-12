<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\TurnEvents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityPreloadDecision;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityRecords;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationCollectedTurnEvent;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvents;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEventSnapshot;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEvents;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventSnapshotModel;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DBALException;
use Throwable;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;

/**
 * The ONE writer of the turn-events table and its readers. Every write is
 *   - IDEMPOTENT: an insert that skips an existing unique {@see AIConversationTurnEvent::$eventKey} (the dialect's
 *     `insertSkippingExistingRows()`: `ON DUPLICATE KEY UPDATE` that changes nothing | `ON CONFLICT DO NOTHING`);
 *   - FENCED by the run claim when the writer holds a token: `INSERT … SELECT … FROM AIConversations WHERE id
 *     = :id AND runToken = :token` — a stale worker whose claim was stolen inserts nothing (0 rows); the caller tells
 *     that from a duplicate through {@see \DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService::holdsRunClaim()}
 *     only where it matters. A null token (the synchronous CLI / eval drive) writes unfenced;
 *   - FAIL-OPEN: a missing table, a refused insert, a broken connection are logged and swallowed — telemetry never
 *     blocks a release, a persist or a turn.
 * Raw SQL by design (the ddd-service-specialist's atomic-write form): the entity class feeds `app:entity:show-sql`
 * (the owner applies the DDL) and the inspector's rights-scoped reads; an event row is never an entity update.
 *
 * @method AIConversationTurnEvent find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 */
class AIConversationTurnEventsService extends EntitiesService
{
    use AITraceLogTrait;

    public const string DEFAULT_ENTITY_CLASS = AIConversationTurnEvent::class;

    /** The retention of the table — its own batched purge ({@see purgeOlderThanDays()}), never the account-state erasure. */
    public const int RETENTION_DAYS = 180;

    /** A snapshot younger than this is never purged even when unreferenced — its referencing event may be in flight. */
    public const int SNAPSHOT_GRACE_HOURS = 24;

    /**
     * @var array<int, AIConversationCollectedTurnEvent[]> Conversation object id → the events
     *      {@see recordEventOfConversation()} built while a collected stretch runs, each with its run token, in call order.
     */
    protected array $collectedEventsByConversation = [];

    /**
     * From now until {@see recordCollectedEventsOfConversation()}, the events {@see recordEventOfConversation()} records
     * for this conversation are built (with the message index of their call) but held back: the turn-start application
     * records them AFTER its collected working-state write, so a crash or a lost claim between the two never leaves an
     * event whose slice is missing. {@see recordEvent()} called directly (the skill-preload replay guard inside its
     * admission transaction) is never held back.
     */
    public function collectEventsOfConversation(AIConversation $conversation): void
    {
        $this->collectedEventsByConversation[spl_object_id($conversation)] ??= [];
    }

    /**
     * End the collected stretch of {@see collectEventsOfConversation()} and record its events in their order;
     * `$recordCollectedEvents = false` drops them (the run claim is lost — the slices they describe were not written).
     */
    public function recordCollectedEventsOfConversation(AIConversation $conversation, bool $recordCollectedEvents = true): void
    {
        $conversationObjectId = spl_object_id($conversation);
        $collectedEvents = $this->collectedEventsByConversation[$conversationObjectId] ?? [];
        unset($this->collectedEventsByConversation[$conversationObjectId]);
        if (!$recordCollectedEvents) {
            return;
        }
        foreach ($collectedEvents as $collectedTurnEvent) {
            try {
                $this->recordEvent($collectedTurnEvent->turnEvent, $collectedTurnEvent->runToken);
            } catch (Throwable $eventError) {
                $this->rethrowInsideClaimFencedWrite($eventError);
                $this->aiTrace('turn.event.recordFailed', ['conversationId' => $conversation->id ?? null, 'kind' => $collectedTurnEvent->turnEvent->kind ?? null, 'message' => $eventError->getMessage()]);
            }
        }
    }

    /**
     * Record one event. Returns true when a row was INSERTED, false on a duplicate, a lost claim or a failure (the
     * three are told apart by the caller only where it matters — a duplicate under a held claim is a replay).
     */
    public function recordEvent(AIConversationTurnEvent $event, ?string $runToken = null): bool
    {
        try {
            $event->eventKey ??= AIConversationTurnEvent::buildEventKey(
                (int) $event->aiConversationId,
                $event->initiatorMessageIndex ?? null,
                $event->evaluationMessageIndex ?? null,
                (string) $event->kind,
                $event->site ?? null,
                $event->subject ?? null
            );
            $event->runToken ??= $runToken;
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $columns = [
                'aiConversationId' => (int) $event->aiConversationId,
                'initiatorMessageIndex' => $event->initiatorMessageIndex ?? null,
                'evaluationMessageIndex' => $event->evaluationMessageIndex ?? null,
                'kind' => (string) $event->kind,
                'site' => $event->site ?? null,
                'subject' => $event->subject ?? null,
                'eventKey' => $event->eventKey,
                'requestId' => $event->requestId ?? null,
                'runToken' => $event->runToken ?? null,
                'arm' => $event->arm ?? null,
                'evalRun' => isset($event->evalRun) ? (int) $event->evalRun : null,
                'overrideSource' => $event->overrideSource ?? null,
                'catalogSnapshotHash' => $event->catalogSnapshotHash ?? null,
                'payload' => $event->payload ?? null,
                'elapsedMs' => $event->elapsedMs ?? null,
                'created' => $now,
                'updated' => $now,
            ];
            $columnList = implode(', ', array_map(static fn (string $column): string => "`$column`", array_keys($columns)));
            $placeholders = implode(', ', array_map(static fn (string $column): string => ":$column", array_keys($columns)));
            $eventsTable = DBAIConversationTurnEventModel::getTableName();
            $parameters = $columns;
            if ($runToken !== null && $runToken !== '') {
                // The FENCE: one statement — the row exists only if the writer still holds the claim.
                $conversationsTable = DBAIConversationModel::getTableName();
                $insertSql = "INSERT INTO `$eventsTable` ($columnList) SELECT $placeholders FROM `$conversationsTable`"
                    . ' WHERE `id` = :fenceConversationId AND `runToken` = :fenceRunToken';
                $parameters['fenceConversationId'] = (int) $event->aiConversationId;
                $parameters['fenceRunToken'] = $runToken;
            } else {
                $insertSql = "INSERT INTO `$eventsTable` ($columnList) VALUES ($placeholders)";
            }
            $sql = EntityManagerFactory::getDialect()->insertSkippingExistingRows($insertSql, $eventsTable, 'eventKey');
            $affectedRows = (int) EntityManagerFactory::getInstance()->getConnection()->executeStatement($sql, $parameters);
            return $affectedRows === 1; // 1 = inserted; 0 = duplicate (or a lost claim) on both engines
        } catch (Throwable $failure) {
            $this->rethrowInsideClaimFencedWrite($failure);
            DDDService::instance()->getLogger()->warning('[AIConversationTurnEvents] event not recorded', [
                'kind' => $event->kind ?? null, 'site' => $event->site ?? null, 'conversationId' => $event->aiConversationId ?? null,
                'reason' => $failure::class . ': ' . mb_substr($failure->getMessage(), 0, 200),
            ]);
            return false;
        }
    }

    /**
     * Record one always-on / site telemetry event of a conversation — fail-open: never throws into the
     * loop, except a database error inside an open claim-fenced write ({@see rethrowInsideClaimFencedWrite()}).
     *
     * @param array<string, mixed> $payload
     * @throws DBALException a database error inside an open claim-fenced write (the write must fail there)
     */
    public function recordEventOfConversation(
        AIConversation $conversation,
        string $kind,
        ?string $site,
        ?int $initiatorMessageIndex,
        ?string $subject,
        ?string $requestId,
        array $payload,
        ?string $runToken = null,
        ?int $elapsedMs = null,
        ?string $arm = null,
        ?string $subjectFallback = null
    ): void {
        try {
            if (!isset($conversation->id)) {
                return;
            }
            $event = AIConversationTurnEvent::newInstance();
            $event->aiConversationId = (int)$conversation->id;
            $event->initiatorMessageIndex = $initiatorMessageIndex;
            // never the lazy oldest-window load (the chunk row is written before the turn loads its tail)
            if (isset($conversation->messages)) {
                $event->evaluationMessageIndex = $conversation->messages->getNewestMessageIndex();
            } else {
                /** @var AIConversationMessagesService $aIConversationMessagesService */
                $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
                $event->evaluationMessageIndex = $aIConversationMessagesService->findNewestMessageIndex($conversation);
            }
            $event->kind = $kind;
            $event->site = $site;
            $event->subject = $subject ?? $subjectFallback;
            $event->requestId = $requestId;
            $event->arm = $arm;
            $event->evalRun = isset($conversation->runSettings) ? ($conversation->runSettings->evalRun ?? null) : null;
            $event->elapsedMs = $elapsedMs;
            $event->setPayloadArray($payload);
            $conversationObjectId = spl_object_id($conversation);
            if (isset($this->collectedEventsByConversation[$conversationObjectId])) {
                // a collected stretch (the turn-start application): recorded after its working-state slices
                $this->collectedEventsByConversation[$conversationObjectId][] = AIConversationCollectedTurnEvent::forTurnEventAndRunToken($event, $runToken);
                return;
            }
            $this->recordEvent($event, $runToken);
        } catch (Throwable $eventError) {
            $this->rethrowInsideClaimFencedWrite($eventError);
            $this->aiTrace('turn.event.recordFailed', ['conversationId' => $conversation->id ?? null, 'kind' => $kind, 'message' => $eventError->getMessage()]);
        }
    }

    /**
     * Replace the payload of an event already recorded under the same {@see AIConversationTurnEvent::$eventKey} — for a
     * decision whose outcome is known only after its insert (the skill-preload admission: the insert is the replay
     * guard, the admission runs after it in the same transaction). FAIL-OPEN like {@see recordEvent()}.
     *
     * @param AIConversationTurnEvent $event
     * @return void
     */
    public function updateEventPayload(AIConversationTurnEvent $event): void
    {
        try {
            EntityManagerFactory::getInstance()->getConnection()->executeStatement(
                'UPDATE `' . DBAIConversationTurnEventModel::getTableName() . '` SET `payload` = :payload, `updated` = :updated WHERE `eventKey` = :eventKey',
                ['payload' => $event->payload ?? null, 'updated' => (new DateTime())->format('Y-m-d H:i:s'), 'eventKey' => $event->eventKey]
            );
        } catch (Throwable $failure) {
            $this->rethrowInsideClaimFencedWrite($failure);
            DDDService::instance()->getLogger()->warning('[AIConversationTurnEvents] event payload not updated', [
                'kind' => $event->kind ?? null, 'site' => $event->site ?? null, 'conversationId' => $event->aiConversationId ?? null,
                'reason' => $failure::class . ': ' . mb_substr($failure->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * Fail-open ends where a claim-fenced write is open: a database error there (a deadlock rolls
     * the whole transaction back on the server) must fail that write — swallowed, its later statements would run
     * under autocommit, outside the fence.
     */
    protected function rethrowInsideClaimFencedWrite(Throwable $failure): void
    {
        if ($failure instanceof DBALException && AIConversationMessagesService::isClaimFencedWriteOpen()) {
            throw $failure;
        }
    }

    /**
     * The BOUNDED idempotence lookup of a site: has this site already decided for this initiator?
     * FAIL-SAFE: a failed read counts as "decided", so a missing or broken table can never cause repeated calls.
     */
    public function hasEventFor(int $conversationId, int $initiatorMessageIndex, string $kind, string $site): bool
    {
        try {
            $count = (int) EntityManagerFactory::getInstance()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM `' . DBAIConversationTurnEventModel::getTableName() . '`'
                . ' WHERE `aiConversationId` = :conversationId AND `kind` = :kind AND `initiatorMessageIndex` = :initiatorMessageIndex AND `site` = :site',
                ['conversationId' => $conversationId, 'kind' => $kind, 'initiatorMessageIndex' => $initiatorMessageIndex, 'site' => $site]
            );
            return $count > 0;
        } catch (Throwable $failure) {
            DDDService::instance()->getLogger()->warning('[AIConversationTurnEvents] idempotence read failed — treated as decided', ['reason' => $failure::class]);
            return true;
        }
    }

    /** The stored decision event of a site for one initiator (the replay source after a crash), or null. */
    /**
     * The integrity validator's event reads, one projection query each — never hydrated events: every
     * skill-preload DECISION of the conversation (initiator, pass, applied, admitted names) and the initiator index of
     * every turn that recorded an event (the turn boundaries).
     *
     * @return AIConversationIntegrityRecords
     * @throws \Doctrine\DBAL\Exception
     */
    public function findIntegrityRecordsOfConversation(AIConversation $conversation): AIConversationIntegrityRecords
    {
        if (!isset($conversation->id)) {
            return AIConversationIntegrityRecords::forPreloadDecisionsAndTurnInitiatorIndexes([], []);
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $sqlDialect = EntityManagerFactory::getDialect();
        $eventsTable = DBAIConversationTurnEventModel::getTableName();
        $preloadDecisions = [];
        // one top-level key each: a JSON null reads as the text `null` on MariaDB and as NULL on PostgreSQL — the
        // reads below treat both as "no value"; an array reads as its JSON text on both engines
        foreach ($connection->fetchAllAssociative(
            'SELECT `initiatorMessageIndex`, ' . $sqlDialect->jsonFieldText('`payload`', 'pass') . ' AS `pass`, '
            . $sqlDialect->jsonFieldText('`payload`', 'applied') . ' AS `applied`, '
            . $sqlDialect->jsonFieldText('`payload`', 'skipReason') . ' AS `skipReason`, '
            . $sqlDialect->jsonFieldText('`payload`', 'admittedSkillNames') . " AS `admittedSkillNames` FROM `$eventsTable`"
            . " WHERE `aiConversationId` = :conversationId AND `kind` = 'decision' AND `site` = 'skillPreload' ORDER BY `id`",
            ['conversationId' => (int)$conversation->id]
        ) as $decisionRow) {
            $admittedSkillNames = json_decode((string)($decisionRow['admittedSkillNames'] ?? 'null'), true);
            $preloadDecisions[] = AIConversationIntegrityPreloadDecision::forTurn(
                (int)$decisionRow['initiatorMessageIndex'],
                is_numeric($decisionRow['pass'] ?? null) ? (int)$decisionRow['pass'] : null,
                $decisionRow['applied'] === 'true',
                is_array($admittedSkillNames) ? array_values(array_map('strval', $admittedSkillNames)) : [],
                is_string($decisionRow['skipReason'] ?? null) && $decisionRow['skipReason'] !== 'null' ? $decisionRow['skipReason'] : null
            );
        }
        $turnInitiatorIndexes = array_map('intval', $connection->fetchFirstColumn(
            "SELECT DISTINCT `initiatorMessageIndex` FROM `$eventsTable` WHERE `aiConversationId` = :conversationId AND `initiatorMessageIndex` IS NOT NULL ORDER BY `initiatorMessageIndex`",
            ['conversationId' => (int)$conversation->id]
        ));
        return AIConversationIntegrityRecords::forPreloadDecisionsAndTurnInitiatorIndexes($preloadDecisions, $turnInitiatorIndexes);
    }

    public function findDecisionEvent(int $conversationId, int $initiatorMessageIndex, string $site): ?AIConversationTurnEvent
    {
        try {
            $events = $this->findForConversation($conversationId, $initiatorMessageIndex);
            foreach ($events?->getElements() ?? [] as $event) {
                if ($event->kind === AIConversationTurnEvent::KIND_DECISION && $event->site === $site) {
                    return $event;
                }
            }
        } catch (Throwable) {
            // fail-open: no replay source = the caller decides afresh, which the unique key still protects
        }
        return null;
    }

    /** The events of a conversation (optionally one turn) — the inspector's read; rights-scoped through the repo. */
    public function findForConversation(int $conversationId, ?int $initiatorMessageIndex = null, int $limit = 500): ?AIConversationTurnEvents
    {
        /** @var DBAIConversationTurnEvents $repo */
        $repo = AIConversationTurnEvents::getRepoClassInstance();
        return $repo->findForConversation($conversationId, $initiatorMessageIndex, $limit);
    }

    /**
     * Store a catalog snapshot by content hash — an insert that skips an existing unique hash, so equal content
     * costs one row however many decisions reference it. Returns the hash to put on the event. Fail-open: a failure
     * returns the hash anyway (the event is still written; the snapshot may be missing, which the stats report).
     *
     * @param array<string, mixed> $snapshot
     */
    public function storeCatalogSnapshot(array $snapshot): string
    {
        [$hash, $json] = AIConversationTurnEventSnapshot::canonicalize($snapshot);
        try {
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $snapshotsTable = DBAIConversationTurnEventSnapshotModel::getTableName();
            EntityManagerFactory::getInstance()->getConnection()->executeStatement(
                EntityManagerFactory::getDialect()->insertSkippingExistingRows(
                    "INSERT INTO `$snapshotsTable` (`hash`, `payload`, `created`, `updated`) VALUES (:hash, :payload, :created, :updated)",
                    $snapshotsTable,
                    'hash'
                ),
                ['hash' => $hash, 'payload' => $json, 'created' => $now, 'updated' => $now]
            );
        } catch (Throwable $failure) {
            DDDService::instance()->getLogger()->warning('[AIConversationTurnEvents] snapshot not stored', ['hash' => $hash, 'reason' => $failure::class]);
        }
        return $hash;
    }

    /**
     * The 180-day retention purge, batched — the table's own lifecycle. A batch reads at most `$batchSize` expired ids
     * on the `created` index and deletes exactly those rows (PostgreSQL has no `DELETE … LIMIT`). Returns the rows
     * deleted.
     */
    public function purgeOlderThanDays(int $days = self::RETENTION_DAYS, int $batchSize = 5000, int $maxBatches = 200): int
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $eventsTable = DBAIConversationTurnEventModel::getTableName();
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
            $affected = (int) $connection->executeStatement(
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
     * Purge the catalog snapshots no event references any more — AFTER the events purge, never by their own age
     * alone (a newer event may reference an old hash); rows younger than the grace are kept regardless, so an
     * insert racing this purge cannot lose its snapshot.
     */
    public function purgeUnreferencedSnapshots(int $batchSize = 5000): int
    {
        $eventsTable = DBAIConversationTurnEventModel::getTableName();
        $snapshotsTable = DBAIConversationTurnEventSnapshotModel::getTableName();
        $graceCutoff = (new DateTime())->modify('-' . self::SNAPSHOT_GRACE_HOURS . ' hours')->format('Y-m-d H:i:s');
        // The ids of one batch are read, then exactly those rows are deleted with the condition repeated: a snapshot
        // an event came to reference in between stays. (A multi-table DELETE takes no LIMIT on MariaDB and does not
        // exist on PostgreSQL.)
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $unreferencedSnapshotIds = array_map('intval', $connection->fetchFirstColumn(
            "SELECT `snapshot`.`id` FROM `$snapshotsTable` `snapshot` WHERE `snapshot`.`created` < :graceCutoff"
            . " AND NOT EXISTS (SELECT 1 FROM `$eventsTable` `event` WHERE `event`.`catalogSnapshotHash` = `snapshot`.`hash`)"
            . ' LIMIT ' . max(1, $batchSize),
            ['graceCutoff' => $graceCutoff]
        ));
        if ($unreferencedSnapshotIds === []) {
            return 0;
        }
        return (int) $connection->executeStatement(
            "DELETE FROM `$snapshotsTable` WHERE `id` IN (:unreferencedSnapshotIds) AND `created` < :graceCutoff"
            . " AND NOT EXISTS (SELECT 1 FROM `$eventsTable` `event` WHERE `event`.`catalogSnapshotHash` = `$snapshotsTable`.`hash`)",
            ['unreferencedSnapshotIds' => $unreferencedSnapshotIds, 'graceCutoff' => $graceCutoff],
            ['unreferencedSnapshotIds' => ArrayParameterType::INTEGER]
        );
    }

    /**
     * The TERMINAL record of a turn (`turnEnd`): written BEFORE the release clears the token, at every
     * release point of the worker path — `answered`, `failed` (over the cap), `gaveUp`; `expired` / `cancelled` are
     * the sweeps' unfenced rows; `superseded` is derived at read time. One row per ATTEMPT (subject = the run token),
     * the latest wins; `answered` and `gaveUp` are absorbing for the derived kinds. Population = an owner-text
     * initiator in a non-delegation, non-synthesized conversation. Everything is derived from the loaded tail.
     */
    public function recordTurnEndEventOfRun(AgentRun $agentRun, AIConversation $conversation, ?string $runToken, string $outcome, bool $recordedLate = false): void
    {
        try {
            if (!isset($conversation->id) || $conversation->isDelegation() || $conversation->isSupervisorTandem()) {
                return;
            }
            $messages = $conversation->messages ?? null;
            $initiator = $messages?->buildCurrentTurn()->getInitiatorMessage();
            if ($initiator === null
                || (!$initiator->isUserRoleText()
                    && !AIConversationMessages::isOwnerFormSubmitInitiator($initiator))
            ) {
                return; // not the population: a signal-started turn, a widget click, a seed (a submitted form is an owner turn)
            }
            $initiatorIndex = $initiator->indexInConversation ?? null;
            if ($initiatorIndex === null) {
                return;
            }
            if ($recordedLate && $this->hasEventFor((int)$conversation->id, $initiatorIndex, AIConversationTurnEvent::KIND_TURN_END, 'turn')) {
                return; // the answering chunk already wrote the terminal
            }
            $initiatorCreated = $initiator->getChangeHistory()->createdTime ?? null;
            $elapsedMs = $initiatorCreated instanceof DateTime ? max(0, (int) round((microtime(true) - (float) $initiatorCreated->format('U.u')) * 1000)) : null;
            $decisionModelSettings = AgentDecisionModelSettings::current();
            $arm = $decisionModelSettings->siteMode(AgentDecisionModelSettings::SITE_SKILL_PRELOAD) === AgentDecisionModelSettings::MODE_COHORT
                ? AgentDecisionModelSettings::resolveArmForAccountId($conversation->accountId ?? null)
                : null;
            $this->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_TURN_END, 'turn', $initiatorIndex, $runToken ?? ('sync:' . ($this->getCurrentEnvelopeId() ?? 'none')), null, [
                'outcome' => $outcome,
                'recordedLate' => $recordedLate,
                'answeredAt' => $outcome === AIConversationTurnEvent::OUTCOME_ANSWERED ? (new DateTime())->format('Y-m-d H:i:s') : null,
                'chatModelCalls' => $messages->buildCurrentTurn()->countModelCallSteps(),
                'loadSkillsCalls' => $messages->buildCurrentTurn()->countToolCallsNamed(LoadSkillsTool::NAME),
                'admittedLoads' => $messages->buildCurrentTurn()->countToolCallsNamed(LoadSkillsTool::NAME, true),
                // the skill loads a widget search made
                'findWidgetsLoads' => $messages->buildCurrentTurn()->countFindWidgetsCallsThatLoadedASkill(),
                'nudges' => $messages->buildCurrentTurn()->countLoopNudges(),
                'turnCostInUSD' => $messages->buildCurrentTurn()->getCostInUSD(),
                'purposeName' => $conversation->purposeName ?? null,
                'overrideSource' => (isset($conversation->runSettings) && ($conversation->runSettings->decisionModelSkillPreload ?? null) !== null) ? AIConversationTurnEvent::OVERRIDE_SOURCE_CONVERSATION : AIConversationTurnEvent::OVERRIDE_SOURCE_NONE,
                'turnStartMs' => $agentRun->turnTimings->buildTurnStartStampsForEvent($initiatorCreated),
                // the last tool call's result persist has no successor call to land on — it rides this write
                'lastPersistAfterMs' => $agentRun->turnTimings->previousToolCallPersistAfterMs,
                'lastPersistAfterBreakdownMs' => $agentRun->turnTimings->previousToolCallPersistAfterBreakdownMs,
            ], $runToken, $elapsedMs, $arm);
        } catch (Throwable $turnEndError) {
            $this->aiTrace('turn.event.turnEndFailed', ['conversationId' => $conversation->id ?? null, 'message' => $turnEndError->getMessage()]);
        } finally {
            // consumed: a later event in this worker must not carry this turn's last persist
            $agentRun->turnTimings->resetPersistAfterCarry();
        }
    }

    /** The always-on `chunk` row: one per invocation, subject = the run token (or the envelope id on the claim-less sync path). */
    public function recordChunkEventOfConversation(AIConversation $conversation, ?string $runToken): void
    {
        try {
            $envelopeId = $this->getCurrentEnvelopeId();
            // runs right after the claim, BEFORE the turn loads its tail: the lazy `messages` relation would load the
            // OLDEST 50 rows (a full-column load per worker turn, and a wrong initiator) — the index comes from one MAX()
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $chunkInitiatorIndex = isset($conversation->messages)
                ? $conversation->messages->getNewestInitiatorMessageIndex()
                : $aIConversationMessagesService->findNewestTurnInitiatorIndex($conversation);
            $this->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_CHUNK, null, $chunkInitiatorIndex, $runToken ?? ($envelopeId !== null ? 'envelope:' . $envelopeId : 'envelope:none'), null, [
                'envelopeId' => $envelopeId,
                'claimed' => $runToken !== null,
                'delegation' => $conversation->isDelegation(),
                'delegatedFromMessageId' => $conversation->delegatedFromMessageId ?? null,
            ], $runToken);
        } catch (Throwable $chunkError) {
            $this->aiTrace('turn.event.chunkFailed', ['conversationId' => $conversation->id ?? null, 'message' => $chunkError->getMessage()]);
        }
    }

    /**
     * @return int|null The id of the innermost open AI usage envelope; null when none is open
     */
    protected function getCurrentEnvelopeId(): ?int
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        return $aIUsageEnvelopesService->getCurrentEnvelopeId();
    }
}
