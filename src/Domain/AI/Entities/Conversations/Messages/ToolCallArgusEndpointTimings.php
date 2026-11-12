<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusSentCallJournalEntries;
use stdClass;

/**
 * The Argus requests of ONE tool call's execution, per endpoint ({@see ToolCallArgusEndpointTiming}). Stored on the
 * call as {@see ToolCall::$argusEndpointTimings}.
 *
 * Two stored forms exist:
 * - the OBJECT form, this class as it serialises: `{"endpointTimings":[{"endpoint":…,"requestCount":…,"totalMs":…,
 *   "maxMs":…,"spanMs":…}]}`. New tool calls are written in it.
 * - the KEYED form under the tool call's former key `argusCallsMs`: a map endpoint → `{"n":…,"ms":…,"max":…,"span":…}`.
 *   Tool calls written before the object form hold it; {@see ToolCall::setPropertiesFromObject()} reads it through
 *   {@see buildObjectFormFromStoredKeyedForm()}. No stored row is rewritten.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallArgusEndpointTimings extends ValueObject
{
    /** The former key of the timings on a stored tool call, holding the keyed form. */
    public const string STORED_KEYED_FORM_KEY = 'argusCallsMs';

    /**
     * @var ToolCallArgusEndpointTiming[] One timing per endpoint, the most expensive (summed milliseconds) first, at
     *      most {@see ToolCall::ARGUS_CALLS_MAX_ENDPOINTS}
     */
    public array $endpointTimings = [];

    /**
     * Sum a slice of the Argus call journal ({@see \DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations::getCallJournal()})
     * per endpoint and keep the most expensive endpoints. Pure. An entry without a start / end stamp (both 0) adds its
     * duration but no span. Endpoints with the same summed milliseconds keep the order they first appear in.
     *
     * @param ArgusSentCallJournalEntries $journalSlice
     * @return static
     */
    public static function buildFromCallJournalSlice(ArgusSentCallJournalEntries $journalSlice): static
    {
        /** @var ToolCallArgusEndpointTiming[] $endpointTimingsByEndpoint */
        $endpointTimingsByEndpoint = [];
        foreach ($journalSlice->getElements() as $journalEntry) {
            $endpoint = $journalEntry->endpoint;
            $endpointTimingsByEndpoint[$endpoint] ??= ToolCallArgusEndpointTiming::forEndpoint($endpoint);
            $hasStartAndEnd = $journalEntry->startedAtMilliseconds > 0 && $journalEntry->endedAtMilliseconds > 0;
            $endpointTimingsByEndpoint[$endpoint]->addRequest(
                $journalEntry->transferMilliseconds,
                $hasStartAndEnd ? $journalEntry->startedAtMilliseconds : null,
                $hasStartAndEnd ? $journalEntry->endedAtMilliseconds : null
            );
        }
        $endpointTimings = array_values($endpointTimingsByEndpoint);
        usort(
            $endpointTimings,
            static fn (ToolCallArgusEndpointTiming $leftEndpointTiming, ToolCallArgusEndpointTiming $rightEndpointTiming): int => $rightEndpointTiming->totalMs <=> $leftEndpointTiming->totalMs
        );
        $toolCallArgusEndpointTimings = new static();
        $toolCallArgusEndpointTimings->endpointTimings = array_slice($endpointTimings, 0, ToolCall::ARGUS_CALLS_MAX_ENDPOINTS);
        return $toolCallArgusEndpointTimings;
    }

    /**
     * The object form of a stored keyed form (endpoint → `{n, ms, max, span}`), in the stored order.
     *
     * @param array|object $storedKeyedForm
     * @return object
     */
    public static function buildObjectFormFromStoredKeyedForm(array|object $storedKeyedForm): object
    {
        $storedEndpointTimings = [];
        foreach ((array)$storedKeyedForm as $endpoint => $storedKeyedTiming) {
            $storedKeyedTimingFields = (array)$storedKeyedTiming;
            $storedEndpointTiming = new stdClass();
            $storedEndpointTiming->endpoint = (string)$endpoint;
            $storedEndpointTiming->requestCount = (int)($storedKeyedTimingFields['n'] ?? 0);
            $storedEndpointTiming->totalMs = (int)($storedKeyedTimingFields['ms'] ?? 0);
            $storedEndpointTiming->maxMs = (int)($storedKeyedTimingFields['max'] ?? 0);
            $storedEndpointTiming->spanMs = (int)($storedKeyedTimingFields['span'] ?? 0);
            $storedEndpointTimings[] = $storedEndpointTiming;
        }
        $objectForm = new stdClass();
        $objectForm->endpointTimings = $storedEndpointTimings;
        return $objectForm;
    }

    /** @return bool No request was sent */
    public function isEmpty(): bool
    {
        return $this->endpointTimings === [];
    }

    /** @return string[] The endpoints (METHOD:path), the most expensive first */
    public function getEndpoints(): array
    {
        return array_column($this->endpointTimings, 'endpoint');
    }

    /**
     * @param string $endpoint
     * @return ToolCallArgusEndpointTiming|null Null when the call sent nothing to the endpoint, or the endpoint was not among the kept ones
     */
    public function getTimingOfEndpoint(string $endpoint): ?ToolCallArgusEndpointTiming
    {
        foreach ($this->endpointTimings as $endpointTiming) {
            if ($endpointTiming->endpoint === $endpoint) {
                return $endpointTiming;
            }
        }
        return null;
    }
}
