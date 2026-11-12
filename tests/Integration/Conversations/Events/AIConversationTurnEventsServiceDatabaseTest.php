<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Events;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AIConversationIntegrityPreloadDecision;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEventSnapshot;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventSnapshotModel;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw statements of {@see AIConversationTurnEventsService} against the database, on both engines: an event
 * written once (unfenced, and fenced by the run claim), its payload replaced, the idempotence lookup, the integrity
 * reads over the stored decision payloads, the catalog snapshot written once per hash, and the two batched purges.
 */
class AIConversationTurnEventsServiceDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 100;

    protected const int OTHER_CONVERSATION_ID = 101;

    protected const string RUN_TOKEN = 'token-100';

    protected AIConversationTurnEventsService $turnEventsService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->turnEventsService = new AIConversationTurnEventsService();
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID, 'runState' => AIConversationRunState::RUNNING, 'runToken' => self::RUN_TOKEN]);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::OTHER_CONVERSATION_ID, 'runState' => AIConversationRunState::IDLE]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testAnEventIsWrittenOnceAndAFencedEventOnlyUnderTheHeldClaim(): void
    {
        $unfencedEvent = $this->buildEvent(self::CONVERSATION_ID, 4, AIConversationTurnEvent::KIND_CHUNK, null, ['claimed' => false]);
        $unfencedEvent->evalRun = true;
        self::assertTrue($this->turnEventsService->recordEvent($unfencedEvent));
        self::assertFalse($this->turnEventsService->recordEvent($this->buildEvent(self::CONVERSATION_ID, 4, AIConversationTurnEvent::KIND_CHUNK, null, ['claimed' => false])), 'the same key a second time');

        self::assertTrue($this->turnEventsService->recordEvent($this->buildEvent(self::CONVERSATION_ID, 5, AIConversationTurnEvent::KIND_TURN_END, 'turn', ['outcome' => 'answered']), self::RUN_TOKEN));
        self::assertFalse($this->turnEventsService->recordEvent($this->buildEvent(self::CONVERSATION_ID, 5, AIConversationTurnEvent::KIND_TURN_END, 'turn', ['outcome' => 'answered']), self::RUN_TOKEN), 'a replay under the held claim');
        self::assertFalse($this->turnEventsService->recordEvent($this->buildEvent(self::CONVERSATION_ID, 6, AIConversationTurnEvent::KIND_TURN_END, 'turn', ['outcome' => 'failed']), 'token-of-another-worker'), 'a lost claim writes nothing');
        self::assertFalse($this->turnEventsService->recordEvent($this->buildEvent(self::OTHER_CONVERSATION_ID, 1, AIConversationTurnEvent::KIND_CHUNK, null, []), self::RUN_TOKEN), 'the token of another conversation');

        $storedEvents = $this->findStoredEvents();
        self::assertCount(2, $storedEvents);
        self::assertSame(4, (int)$storedEvents[0]['initiatorMessageIndex']);
        self::assertSame(AIConversationTurnEvent::KIND_CHUNK, $storedEvents[0]['kind']);
        self::assertNull($storedEvents[0]['runToken']);
        self::assertTrue((bool)$storedEvents[0]['evalRun']);
        self::assertSame(['claimed' => false], json_decode((string)$storedEvents[0]['payload'], true));
        self::assertSame(self::RUN_TOKEN, $storedEvents[1]['runToken']);
        self::assertSame('turn', $storedEvents[1]['site']);
        self::assertSame(1234, (int)$storedEvents[1]['elapsedMs']);
    }

    public function testThePayloadOfAnEventIsReplacedAndTheIdempotenceLookupFindsTheEventOfASite(): void
    {
        $decisionEvent = $this->buildEvent(self::CONVERSATION_ID, 7, AIConversationTurnEvent::KIND_DECISION, 'skillPreload', ['applied' => false]);
        self::assertTrue($this->turnEventsService->recordEvent($decisionEvent));
        $decisionEvent->setPayloadArray(['applied' => true, 'admittedSkillNames' => ['Setup.General']]);
        $this->turnEventsService->updateEventPayload($decisionEvent);

        self::assertSame(['applied' => true, 'admittedSkillNames' => ['Setup.General']], json_decode((string)$this->findStoredEvents()[0]['payload'], true));
        self::assertTrue($this->turnEventsService->hasEventFor(self::CONVERSATION_ID, 7, AIConversationTurnEvent::KIND_DECISION, 'skillPreload'));
        self::assertFalse($this->turnEventsService->hasEventFor(self::CONVERSATION_ID, 8, AIConversationTurnEvent::KIND_DECISION, 'skillPreload'));
        // the text columns of the schema compare without case on both engines (the PostgreSQL schema's collation)
        self::assertTrue($this->turnEventsService->hasEventFor(self::CONVERSATION_ID, 7, AIConversationTurnEvent::KIND_DECISION, 'SKILLPRELOAD'));
    }

    public function testTheIntegrityReadsReturnEveryPreloadDecisionAndTheInitiatorOfEveryTurn(): void
    {
        $this->recordDecision(3, ['pass' => 1, 'applied' => true, 'admittedSkillNames' => ['Setup.General', 'Setup.Phone'], 'skipReason' => null]);
        $this->recordDecision(9, ['pass' => null, 'applied' => false, 'skipReason' => 'noCandidates']);
        $this->recordDecision(12, []);
        $this->turnEventsService->recordEvent($this->buildEvent(self::CONVERSATION_ID, 9, AIConversationTurnEvent::KIND_CHUNK, null, []));
        $this->turnEventsService->recordEvent($this->buildEvent(self::CONVERSATION_ID, null, AIConversationTurnEvent::KIND_EVICTION, null, []));
        $this->turnEventsService->recordEvent($this->buildEvent(self::OTHER_CONVERSATION_ID, 2, AIConversationTurnEvent::KIND_DECISION, 'skillPreload', ['pass' => 4]));

        $integrityRecords = $this->turnEventsService->findIntegrityRecordsOfConversation($this->buildConversation(self::CONVERSATION_ID));

        self::assertSame([
            [3, 1, true, ['Setup.General', 'Setup.Phone'], null],
            [9, null, false, [], 'noCandidates'],
            [12, null, false, [], null],
        ], array_map(
            static fn (AIConversationIntegrityPreloadDecision $preloadDecision): array => [
                $preloadDecision->initiatorMessageIndex,
                $preloadDecision->pass,
                $preloadDecision->applied,
                $preloadDecision->admittedSkillNames,
                $preloadDecision->skipReason,
            ],
            $integrityRecords->preloadDecisions
        ));
        self::assertSame([3, 9, 12], $integrityRecords->turnInitiatorIndexes);
        $integrityRecordsOfAnUnstoredConversation = $this->turnEventsService->findIntegrityRecordsOfConversation(new AIConversation());
        self::assertSame([], $integrityRecordsOfAnUnstoredConversation->preloadDecisions);
        self::assertSame([], $integrityRecordsOfAnUnstoredConversation->turnInitiatorIndexes);
    }

    public function testACatalogSnapshotIsStoredOncePerHash(): void
    {
        $snapshot = ['skills' => ['Setup.General' => 'the general setup']];
        $hash = $this->turnEventsService->storeCatalogSnapshot($snapshot);
        self::assertSame(AIConversationTurnEventSnapshot::canonicalize($snapshot)[0], $hash);
        self::assertSame($hash, $this->turnEventsService->storeCatalogSnapshot($snapshot));
        $this->turnEventsService->storeCatalogSnapshot(['skills' => []]);

        self::assertSame(2, $this->countRows(DBAIConversationTurnEventSnapshotModel::getTableName()));
    }

    public function testThePurgesDeleteOldEventsInBatchesAndThenTheSnapshotsNoEventReferences(): void
    {
        $twoHundredDaysAgo = $this->formatMomentDaysAgo(200);
        foreach ([1, 2, 3, 4, 5] as $oldEventNumber) {
            $this->insertStoredEvent(500 + $oldEventNumber, 'old-' . $oldEventNumber, $twoHundredDaysAgo, null);
        }
        $this->insertStoredEvent(510, 'recent-referencing', $this->formatMomentDaysAgo(1), 'hash-referenced');
        foreach (['hash-referenced' => 2, 'hash-unreferenced' => 2, 'hash-unreferenced-young' => 0] as $hash => $daysAgo) {
            $this->insertRow(DBAIConversationTurnEventSnapshotModel::getTableName(), [
                'hash' => $hash,
                'payload' => '{}',
                'created' => $daysAgo === 0 ? (new DateTime())->format('Y-m-d H:i:s') : $this->formatMomentDaysAgo($daysAgo),
            ]);
        }

        self::assertSame(5, $this->turnEventsService->purgeOlderThanDays(180, 2));
        self::assertSame(['recent-referencing'], array_column($this->findStoredEvents(), 'eventKey'));
        self::assertSame(0, $this->turnEventsService->purgeOlderThanDays(180, 2));
        $this->insertStoredEvent(520, 'old-6', $twoHundredDaysAgo, null);
        $this->insertStoredEvent(521, 'old-7', $twoHundredDaysAgo, null);
        self::assertSame(1, $this->turnEventsService->purgeOlderThanDays(180, 1, 1), 'one batch of one row at most');

        self::assertSame(1, $this->turnEventsService->purgeUnreferencedSnapshots());
        $storedHashes = $this->getDatabaseConnection()->fetchFirstColumn(
            'SELECT ' . $this->getSqlDialect()->quote('hash') . ' FROM ' . $this->getSqlDialect()->quote(DBAIConversationTurnEventSnapshotModel::getTableName())
            . ' ORDER BY ' . $this->getSqlDialect()->quote('hash')
        );
        self::assertSame(['hash-referenced', 'hash-unreferenced-young'], $storedHashes);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function buildEvent(int $conversationId, ?int $initiatorMessageIndex, string $kind, ?string $site, array $payload): AIConversationTurnEvent
    {
        $event = new AIConversationTurnEvent();
        $event->aiConversationId = $conversationId;
        $event->initiatorMessageIndex = $initiatorMessageIndex;
        $event->evaluationMessageIndex = $initiatorMessageIndex;
        $event->kind = $kind;
        $event->site = $site;
        $event->elapsedMs = 1234;
        $event->setPayloadArray($payload);
        return $event;
    }

    /** @param array<string, mixed> $payload */
    protected function recordDecision(int $initiatorMessageIndex, array $payload): void
    {
        self::assertTrue($this->turnEventsService->recordEvent($this->buildEvent(self::CONVERSATION_ID, $initiatorMessageIndex, AIConversationTurnEvent::KIND_DECISION, 'skillPreload', $payload)));
    }

    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        return $conversation;
    }

    protected function insertStoredEvent(int $eventId, string $eventKey, string $created, ?string $catalogSnapshotHash): void
    {
        $this->insertRow(DBAIConversationTurnEventModel::getTableName(), [
            'id' => $eventId,
            'aiConversationId' => self::CONVERSATION_ID,
            'kind' => AIConversationTurnEvent::KIND_DECISION,
            'eventKey' => $eventKey,
            'catalogSnapshotHash' => $catalogSnapshotHash,
            'created' => $created,
        ]);
    }

    protected function formatMomentDaysAgo(int $days): string
    {
        return (new DateTime())->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
    }

    /** @return array<int, array<string, mixed>> The stored events in id order */
    protected function findStoredEvents(): array
    {
        $sqlDialect = $this->getSqlDialect();
        return $this->getDatabaseConnection()->fetchAllAssociative(
            'SELECT * FROM ' . $sqlDialect->quote(DBAIConversationTurnEventModel::getTableName()) . ' ORDER BY ' . $sqlDialect->quote('id')
        );
    }

    protected function countRows(string $tableName): int
    {
        return (int)$this->getDatabaseConnection()->fetchOne('SELECT COUNT(*) FROM ' . $this->getSqlDialect()->quote($tableName));
    }
}
