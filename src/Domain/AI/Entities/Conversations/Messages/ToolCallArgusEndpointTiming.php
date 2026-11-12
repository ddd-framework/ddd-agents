<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The HTTP requests ONE tool call sent to ONE Argus endpoint (METHOD:path), summed from
 * {@see \DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations::$callJournal}: how many, their summed and their slowest
 * transfer milliseconds, and the wall time they covered. One element of
 * {@see ToolCallArgusEndpointTimings::$endpointTimings}.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallArgusEndpointTiming extends ValueObject
{
    /** @var string The endpoint (METHOD:path) */
    public string $endpoint = '';

    /** @var int How many requests went to the endpoint */
    public int $requestCount = 0;

    /** @var int Their summed transfer milliseconds */
    public int $totalMs = 0;

    /** @var int The slowest request's milliseconds */
    public int $maxMs = 0;

    /**
     * @var int The wall time the endpoint's requests covered: close to the total means they ran one after the other,
     *      close to the slowest means they ran at the same time; the total when the journal carried no start and end
     *      stamps
     */
    public int $spanMs = 0;

    /** @var int|null The earliest request start stamp the journal carried; only held while the requests are summed, not stored */
    protected ?int $firstStartMs = null;

    /** @var int|null The latest request end stamp the journal carried; only held while the requests are summed, not stored */
    protected ?int $lastEndMs = null;

    /**
     * @param string $endpoint
     * @return static
     */
    public static function forEndpoint(string $endpoint): static
    {
        $toolCallArgusEndpointTiming = new static();
        $toolCallArgusEndpointTiming->endpoint = $endpoint;
        return $toolCallArgusEndpointTiming;
    }

    /**
     * Count one request of the endpoint; its start and end stamps widen the covered wall time when both are known.
     *
     * @param int $milliseconds
     * @param int|null $startMs
     * @param int|null $endMs
     * @return void
     */
    public function addRequest(int $milliseconds, ?int $startMs, ?int $endMs): void
    {
        $this->requestCount++;
        $this->totalMs += $milliseconds;
        $this->maxMs = max($this->maxMs, $milliseconds);
        if ($startMs !== null && $endMs !== null) {
            $this->firstStartMs = min($this->firstStartMs ?? $startMs, $startMs);
            $this->lastEndMs = max($this->lastEndMs ?? $endMs, $endMs);
        }
        $this->spanMs = ($this->firstStartMs !== null && $this->lastEndMs !== null) ? $this->lastEndMs - $this->firstStartMs : $this->totalMs;
    }
}
