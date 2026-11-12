<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Events;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEventModel;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The raw statements of {@see AIConversationTraceEventsService} against the database, on both engines: the
 * multi-row insert that skips existing event keys and answers the rows it wrote (split above the rows per
 * statement), the link of a call's events to its conversation, and the batched retention purge.
 */
class AIConversationTraceEventsServiceDatabaseTest extends DatabaseTestCase
{
    protected const int CONVERSATION_ID = 100;

    protected const int VOICE_CALL_ID = 70;

    protected AIConversationTraceEventsService $traceEventsService;

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->traceEventsService = new AIConversationTraceEventsService();
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID, 'runState' => AIConversationRunState::IDLE]);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testEventsAreWrittenInOneStatementAndAnExistingEventKeyIsSkipped(): void
    {
        self::assertSame(0, $this->traceEventsService->recordEvents([]));
        $truncatedEvent = $this->buildEvent('key-1', self::CONVERSATION_ID);
        $truncatedEvent->truncated = true;
        $truncatedEvent->originalLength = 9000;
        self::assertSame(2, $this->traceEventsService->recordEvents([$truncatedEvent, $this->buildEvent('key-2', null)]));
        self::assertSame(1, $this->traceEventsService->recordEvents([$this->buildEvent('key-2', null), $this->buildEvent('key-3', null)]), 'key-2 exists');
        self::assertFalse($this->traceEventsService->recordEvent($this->buildEvent('key-3', null)));
        self::assertTrue($this->traceEventsService->recordEvent($this->buildEvent('key-4', null)));

        $storedEvents = $this->findStoredEvents();
        self::assertSame(['key-1', 'key-2', 'key-3', 'key-4'], array_column($storedEvents, 'eventKey'));
        self::assertTrue((bool)$storedEvents[0]['truncated']);
        self::assertSame(9000, (int)$storedEvents[0]['originalLength']);
        self::assertFalse((bool)$storedEvents[1]['truncated']);
        self::assertSame(1762000000123, (int)$storedEvents[1]['occurredAtUnixMs'], 'a moment in milliseconds needs the big integer column');
        self::assertSame(['text' => 'sentence'], json_decode((string)$storedEvents[1]['payload'], true));
    }

    public function testABatchAboveTheRowsPerStatementIsSplitAndEveryNewRowIsCounted(): void
    {
        $traceEvents = [];
        for ($eventNumber = 1; $eventNumber <= AIConversationTraceEventsService::ROWS_PER_STATEMENT + 5; $eventNumber++) {
            $traceEvents[] = $this->buildEvent('bulk-' . $eventNumber, null);
        }
        self::assertSame(AIConversationTraceEventsService::ROWS_PER_STATEMENT + 5, $this->traceEventsService->recordEvents($traceEvents));
        self::assertSame(0, $this->traceEventsService->recordEvents($traceEvents));
    }

    public function testTheEventsOfACallAreLinkedToItsConversationOnce(): void
    {
        $this->traceEventsService->recordEvents([$this->buildEvent('setup-1', null), $this->buildEvent('setup-2', null), $this->buildEvent('linked', self::CONVERSATION_ID)]);
        $otherCallEvent = $this->buildEvent('other-call', null);
        $otherCallEvent->agentVoiceCallId = self::VOICE_CALL_ID + 1;
        $this->traceEventsService->recordEvent($otherCallEvent);

        $this->traceEventsService->attachCallEventsToConversation(self::VOICE_CALL_ID, self::CONVERSATION_ID);

        $conversationIdByEventKey = array_column($this->findStoredEvents(), 'aiConversationId', 'eventKey');
        self::assertSame(self::CONVERSATION_ID, (int)$conversationIdByEventKey['setup-1']);
        self::assertSame(self::CONVERSATION_ID, (int)$conversationIdByEventKey['setup-2']);
        self::assertSame(self::CONVERSATION_ID, (int)$conversationIdByEventKey['linked']);
        self::assertNull($conversationIdByEventKey['other-call']);
    }

    public function testTheRetentionPurgeDeletesOldEventsInBatches(): void
    {
        $this->traceEventsService->recordEvents([$this->buildEvent('recent', null)]);
        $twoHundredDaysAgo = (new DateTime())->modify('-200 days')->format('Y-m-d H:i:s');
        foreach ([1, 2, 3] as $oldEventNumber) {
            $this->insertRow(DBAIConversationTraceEventModel::getTableName(), [
                'id' => 900 + $oldEventNumber,
                'agentVoiceCallId' => self::VOICE_CALL_ID,
                'occurredAtUnixMs' => 1,
                'source' => AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                'eventType' => AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED,
                'eventKey' => 'old-' . $oldEventNumber,
                'created' => $twoHundredDaysAgo,
            ]);
        }

        self::assertSame(1, $this->traceEventsService->purgeOlderThanDays(180, 1, 1), 'one batch of one row at most');
        self::assertSame(2, $this->traceEventsService->purgeOlderThanDays(180, 2));
        self::assertSame(['recent'], array_column($this->findStoredEvents(), 'eventKey'));
    }

    protected function buildEvent(string $eventKey, ?int $conversationId): AIConversationTraceEvent
    {
        $traceEvent = new AIConversationTraceEvent();
        $traceEvent->aiConversationId = $conversationId;
        $traceEvent->agentVoiceCallId = self::VOICE_CALL_ID;
        $traceEvent->occurredAtUnixMs = 1762000000123;
        $traceEvent->source = AIConversationTraceEvent::SOURCE_VOICE_WORKER;
        $traceEvent->eventType = AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED;
        $traceEvent->eventKey = $eventKey;
        $traceEvent->payload = json_encode(['text' => 'sentence']);
        return $traceEvent;
    }

    /** @return array<int, array<string, mixed>> The stored events in id order */
    protected function findStoredEvents(): array
    {
        $sqlDialect = $this->getSqlDialect();
        return $this->getDatabaseConnection()->fetchAllAssociative(
            'SELECT * FROM ' . $sqlDialect->quote(DBAIConversationTraceEventModel::getTableName()) . ' ORDER BY ' . $sqlDialect->quote('id')
        );
    }
}
