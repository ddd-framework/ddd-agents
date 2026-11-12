<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunArgusRequest;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunCreatedIdOrdinal;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunCreatedIdReadReport;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunDispatchedMessage;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunUnscopedTableRead;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecordingPlayedRequest;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecordingPlayedRequests;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The records the recorded-run comparison reads are value objects: the dispatched messages, the Argus requests of
 * both runs, the created-id read report and the creation ordinal of a recorded created id.
 */
class AgentRecordedRunValueObjectsTest extends UnitTestCase
{
    public function testADispatchedMessageNamesItsClassWithoutTheNamespace(): void
    {
        $dispatchedMessage = AgentRecordedRunDispatchedMessage::forMessageClassAndBody('DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage', '{"a":1}');

        self::assertSame('AIConversationResumeMessage', $dispatchedMessage->getShortClassName());
        self::assertNull($dispatchedMessage->outcome);
        $dispatchSnapshot = AgentRecordedRunComparison::buildDispatchSnapshot([$dispatchedMessage], AgentRecordedRunIdNormalization::withHighWaterMarks([], []));
        self::assertSame(['_label' => 'AIConversationResumeMessage', 'messageClass' => $dispatchedMessage->messageClass, 'body' => '{"a":1}', 'outcome' => ''], $dispatchSnapshot[0]);
    }

    public function testAPlayedRequestCarriesItsAnsweringCallAndARequestWithoutOneIsComparedAtItsOwnPosition(): void
    {
        $playedRequest = new ArgusTransportRecordingPlayedRequest();
        $playedRequest->endpoint = 'POST:https://gtw/chat';
        $playedRequest->requestBody = '{"q":1}';
        $playedRequest->answeredByPosition = 4;
        $playedRequest->kind = 'model';
        $playedRequest->turnOrdinal = 2;
        $playedRequests = new ArgusTransportRecordingPlayedRequests();
        $playedRequests->add($playedRequest);

        $playedArgusRequests = AgentRecordedRunComparison::buildPlayedArgusRequests($playedRequests);
        self::assertCount(1, $playedArgusRequests);
        self::assertSame(['POST:https://gtw/chat', '{"q":1}', 4, 'model', 2], [
            $playedArgusRequests[0]->endpoint, $playedArgusRequests[0]->requestBody, $playedArgusRequests[0]->answeredByPosition,
            $playedArgusRequests[0]->kind, $playedArgusRequests[0]->turnOrdinal,
        ]);
        self::assertSame(4, $playedArgusRequests[0]->getComparedRecordedPosition(0));
        self::assertNull(AgentRecordedRunArgusRequest::forReplayedRequest('POST:x', '{}', null)->getComparedRecordedPosition(3), 'a request no recorded call answered');
        self::assertSame(3, AgentRecordedRunArgusRequest::forEndpointAndRequestBody('POST:x', '{}')->getComparedRecordedPosition(3), 'an unknown answering call: its own position');
    }

    public function testTheComparisonReportsAnUnansweredAndAChangedRequest(): void
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        $comparison = AgentRecordedRunComparison::buildComparison(
            [],
            [],
            [2 => AgentRecordedRunArgusRequest::forEndpointAndRequestBody('POST:https://gtw/chat', '{"q":1}')],
            [
                AgentRecordedRunArgusRequest::forReplayedRequest('POST:https://gtw/chat', '{"q":2}', 2),
                AgentRecordedRunArgusRequest::forReplayedRequest('POST:https://gtw/decide', '{}', null),
            ],
            $idNormalization,
            $idNormalization,
            []
        );

        self::assertSame([
            'Argus calls: 1 recorded, 2 replayed',
            'call #0 (recorded #2) POST:https://gtw/chat',
            'call #1 POST:https://gtw/decide: no recorded call answered it',
        ], array_map(static fn (string $difference): string => explode(': recorded «', $difference)[0], $comparison->differences));
    }

    public function testTheCreatedIdReadReportReadsOnlyItsTwoKeysOfTheStoredRead(): void
    {
        $createdIdReadReport = AgentRecordedRunCreatedIdReadReport::fromStoredReport([
            'idsByTable' => ['keywords' => [1, 2]],
            'unscopedTables' => ['keyword_sites' => ['read' => 3, 'allocated' => 5], 'sites' => ['read' => 1, 'allocated' => 1]],
        ]);

        self::assertSame([], $createdIdReadReport->cappedTables);
        self::assertContainsOnlyInstancesOf(AgentRecordedRunUnscopedTableRead::class, $createdIdReadReport->unscopedTableReads);
        self::assertSame(['keyword_sites', 3, 5], [
            $createdIdReadReport->unscopedTableReads[0]->table, $createdIdReadReport->unscopedTableReads[0]->readIdCount, $createdIdReadReport->unscopedTableReads[0]->allocatedIdCount,
        ]);
        self::assertSame('sites', $createdIdReadReport->unscopedTableReads[1]->table);
        self::assertSame([], AgentRecordedRunCreatedIdReadReport::fromStoredReport([])->unscopedTableReads);
    }

    public function testACreatedIdOrdinalFindsTheOtherRunsIdOfTheSameTableAndOrdinal(): void
    {
        $createdIdOrdinal = AgentRecordedRunCreatedIdOrdinal::forTableAndOrdinal('keywords', 2);

        self::assertSame(9000202, $createdIdOrdinal->getCounterpartIdIn(['keywords' => [9000201, 9000202]]));
        self::assertNull($createdIdOrdinal->getCounterpartIdIn(['keywords' => [9000201]]));
        self::assertNull($createdIdOrdinal->getCounterpartIdIn([]));
    }
}
