<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallArgusEndpointTiming;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallArgusEndpointTimings;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusSentCallJournalEntries;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusSentCallJournalEntry;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The Argus requests of a tool call's execution are a value object on the call: summed per endpoint from the call
 * journal, written in the object form, and read back from a row that still holds the former map under `argusCallsMs`.
 */
class ToolCallArgusEndpointTimingsTest extends UnitTestCase
{
    /** The former map exactly as rows written before the object form hold it. */
    protected const string KEYED_ARGUS_TIMINGS_JSON = '{"POST:/search":{"n":2,"ms":1300,"max":900,"span":950},"GET:/probe":{"n":1,"ms":40,"max":40,"span":40}}';

    protected function buildJournalEntry(string $endpoint, int $transferMilliseconds, int $startedAtMilliseconds, int $endedAtMilliseconds): ArgusSentCallJournalEntry
    {
        $journalEntry = new ArgusSentCallJournalEntry();
        $journalEntry->endpoint = $endpoint;
        $journalEntry->transferMilliseconds = $transferMilliseconds;
        $journalEntry->statusCode = 200;
        $journalEntry->startedAtMilliseconds = $startedAtMilliseconds;
        $journalEntry->endedAtMilliseconds = $endedAtMilliseconds;
        return $journalEntry;
    }

    protected function buildJournalSlice(): ArgusSentCallJournalEntries
    {
        $probeJournalEntry = $this->buildJournalEntry('GET:/probe', 40, 0, 0);
        $firstSearchJournalEntry = $this->buildJournalEntry('POST:/search', 900, 1000, 1900);
        $secondSearchJournalEntry = $this->buildJournalEntry('POST:/search', 400, 1550, 1950);
        $journalSlice = new ArgusSentCallJournalEntries();
        $journalSlice->add($probeJournalEntry, $firstSearchJournalEntry, $secondSearchJournalEntry);
        return $journalSlice;
    }

    /**
     * @param ToolCallArgusEndpointTimings|null $toolCallArgusEndpointTimings
     * @param string $message
     * @return void
     */
    protected function assertTheTimingsOfTheTwoEndpoints(?ToolCallArgusEndpointTimings $toolCallArgusEndpointTimings, string $message): void
    {
        self::assertNotNull($toolCallArgusEndpointTimings, $message);
        self::assertContainsOnlyInstancesOf(ToolCallArgusEndpointTiming::class, $toolCallArgusEndpointTimings->endpointTimings, $message);
        self::assertSame(['POST:/search', 'GET:/probe'], $toolCallArgusEndpointTimings->getEndpoints(), $message . ': the most expensive endpoint first');
        $searchTiming = $toolCallArgusEndpointTimings->getTimingOfEndpoint('POST:/search');
        self::assertSame([2, 1300, 900, 950], [$searchTiming->requestCount, $searchTiming->totalMs, $searchTiming->maxMs, $searchTiming->spanMs], $message . ': search');
        $probeTiming = $toolCallArgusEndpointTimings->getTimingOfEndpoint('GET:/probe');
        self::assertSame([1, 40, 40, 40], [$probeTiming->requestCount, $probeTiming->totalMs, $probeTiming->maxMs, $probeTiming->spanMs], $message . ': probe');
        self::assertNull($toolCallArgusEndpointTimings->getTimingOfEndpoint('GET:/other'), $message);
    }

    public function testTheJournalSliceIsSummedPerEndpointWithTheMostExpensiveEndpointFirst(): void
    {
        $toolCallArgusEndpointTimings = ToolCallArgusEndpointTimings::buildFromCallJournalSlice($this->buildJournalSlice());

        // two overlapping search requests cover 1000–1950; the probe carried no stamps, so its span is its total
        $this->assertTheTimingsOfTheTwoEndpoints($toolCallArgusEndpointTimings, 'summed from the journal');
        self::assertFalse($toolCallArgusEndpointTimings->isEmpty());
    }

    public function testOnlyTheMostExpensiveEndpointsAreKept(): void
    {
        $journalSlice = new ArgusSentCallJournalEntries();
        for ($endpointNumber = 1; $endpointNumber <= ToolCall::ARGUS_CALLS_MAX_ENDPOINTS + 5; $endpointNumber++) {
            $journalEntry = $this->buildJournalEntry('GET:/endpoint-' . $endpointNumber, $endpointNumber, 0, 0);
            $journalSlice->add($journalEntry);
        }

        $toolCallArgusEndpointTimings = ToolCallArgusEndpointTimings::buildFromCallJournalSlice($journalSlice);

        self::assertCount(ToolCall::ARGUS_CALLS_MAX_ENDPOINTS, $toolCallArgusEndpointTimings->endpointTimings);
        self::assertSame('GET:/endpoint-' . (ToolCall::ARGUS_CALLS_MAX_ENDPOINTS + 5), $toolCallArgusEndpointTimings->getEndpoints()[0]);
        self::assertNull($toolCallArgusEndpointTimings->getTimingOfEndpoint('GET:/endpoint-5'), 'the cheapest endpoints are dropped');
    }

    public function testAToolCallThatSentNothingCarriesNoTimings(): void
    {
        $toolCall = new ToolCall();
        $toolCall->applyArgusCallJournalSlice(new ArgusSentCallJournalEntries());

        self::assertNull($toolCall->argusEndpointTimings);
    }

    public function testANewToolCallIsStoredInTheObjectFormAndReadsBack(): void
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_new';
        $toolCall->name = 'searchTheWeb';
        $toolCall->applyArgusCallJournalSlice($this->buildJournalSlice());

        $toolCallColumn = (string)json_encode($toolCall->mapToRepository());
        $storedToolCall = json_decode($toolCallColumn, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey(ToolCallArgusEndpointTimings::STORED_KEYED_FORM_KEY, $storedToolCall);
        $storedEndpointTimings = $storedToolCall['argusEndpointTimings']['endpointTimings'];
        self::assertSame(
            ['endpoint' => 'POST:/search', 'requestCount' => 2, 'totalMs' => 1300, 'maxMs' => 900, 'spanMs' => 950],
            array_intersect_key($storedEndpointTimings[0], array_flip(['endpoint', 'requestCount', 'totalMs', 'maxMs', 'spanMs']))
        );
        self::assertArrayNotHasKey('firstStartMs', $storedEndpointTimings[0], 'the stamps held while summing are not stored');
        self::assertArrayNotHasKey('lastEndMs', $storedEndpointTimings[0]);

        $readToolCall = new ToolCall();
        $readToolCall->mapFromRepository($toolCallColumn);
        $this->assertTheTimingsOfTheTwoEndpoints($readToolCall->argusEndpointTimings, 'read from the object form');
    }

    public function testAToolCallStoredWithTheFormerMapReadsBackWithTheSameTimings(): void
    {
        $oldToolCallColumn = '{"toolCallId":"call_old","name":"searchTheWeb","argusCallsMs":' . self::KEYED_ARGUS_TIMINGS_JSON . '}';

        $oldToolCall = new ToolCall();
        $oldToolCall->mapFromRepository($oldToolCallColumn);

        $this->assertTheTimingsOfTheTwoEndpoints($oldToolCall->argusEndpointTimings, 'read from the former map');
        self::assertStringNotContainsString('"argusCallsMs"', (string)json_encode($oldToolCall->mapToRepository()), 'the next write of the call uses the object form');
    }

    public function testAToolCallStoredWithoutTimingsReadsBackWithoutThem(): void
    {
        $toolCall = new ToolCall();
        $toolCall->mapFromRepository('{"toolCallId":"call_plain","name":"searchTheWeb","argusCallsMs":null}');

        self::assertNull($toolCall->argusEndpointTimings);
    }
}
