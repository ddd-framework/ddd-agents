<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Metrics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One stretch of time the conversation metrics measure — an agent gap, a model call, a tool execution, the life of a
 * delegation batch — as unix seconds. A stretch whose end is not known yet (a delegation batch that is still open)
 * has no end. Collected in {@see AIConversationMetricsTimeIntervals}.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMetricsTimeInterval extends ValueObject
{
    /** @var float The start, unix seconds */
    public float $startUnixSeconds = 0.0;

    /** @var float|null The end, unix seconds; null while the measured stretch is still open */
    public ?float $endUnixSeconds = null;

    /**
     * @param float $startUnixSeconds
     * @param float|null $endUnixSeconds Null while the measured stretch is still open
     * @return static
     */
    public static function fromStartToEnd(float $startUnixSeconds, ?float $endUnixSeconds): static
    {
        $timeInterval = new static();
        $timeInterval->startUnixSeconds = $startUnixSeconds;
        $timeInterval->endUnixSeconds = $endUnixSeconds;
        return $timeInterval;
    }

    /** @return bool Whether the interval has an end that lies after its start */
    public function hasPositiveLength(): bool
    {
        return $this->endUnixSeconds !== null && $this->endUnixSeconds > $this->startUnixSeconds;
    }

    /** @return float The length in seconds; 0.0 for an open interval and for one whose end is not after its start */
    public function getSeconds(): float
    {
        return $this->hasPositiveLength() ? $this->endUnixSeconds - $this->startUnixSeconds : 0.0;
    }

    /**
     * The same interval ending at `$latestEndUnixSeconds` at the latest: an open interval ends there, a later end is
     * cut back to it.
     *
     * @param float $latestEndUnixSeconds
     * @return static
     */
    public function buildWithEndCappedAt(float $latestEndUnixSeconds): static
    {
        return static::fromStartToEnd(
            $this->startUnixSeconds,
            min($this->endUnixSeconds ?? $latestEndUnixSeconds, $latestEndUnixSeconds)
        );
    }
}
