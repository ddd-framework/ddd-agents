<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Metrics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A list of measured stretches of time ({@see AIConversationMetricsTimeInterval}) with the arithmetic the
 * conversation metrics need: the seconds the stretches cover together (stretches that overlap count once) and the
 * seconds two lists cover in common.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationMetricsTimeIntervals extends ValueObject
{
    /** @var AIConversationMetricsTimeInterval[] The intervals, in the order they were added */
    public array $intervals = [];

    /**
     * @param AIConversationMetricsTimeInterval ...$timeIntervals
     * @return static
     */
    public static function forIntervals(AIConversationMetricsTimeInterval ...$timeIntervals): static
    {
        $metricsTimeIntervals = new static();
        $metricsTimeIntervals->intervals = array_values($timeIntervals);
        return $metricsTimeIntervals;
    }

    /**
     * @param AIConversationMetricsTimeInterval $timeInterval
     * @return void
     */
    public function addInterval(AIConversationMetricsTimeInterval $timeInterval): void
    {
        $this->intervals[] = $timeInterval;
    }

    /**
     * @param float $startUnixSeconds
     * @param float|null $endUnixSeconds Null while the measured stretch is still open
     * @return void
     */
    public function addStartToEnd(float $startUnixSeconds, ?float $endUnixSeconds): void
    {
        $this->intervals[] = AIConversationMetricsTimeInterval::fromStartToEnd($startUnixSeconds, $endUnixSeconds);
    }

    /** @return int How many intervals the list holds */
    public function count(): int
    {
        return count($this->intervals);
    }

    /**
     * The intervals of this list followed by those of the other lists — not merged.
     *
     * @param AIConversationMetricsTimeIntervals ...$otherTimeIntervals
     * @return static
     */
    public function buildJoinedWith(AIConversationMetricsTimeIntervals ...$otherTimeIntervals): static
    {
        $joinedTimeIntervals = new static();
        $joinedTimeIntervals->intervals = $this->intervals;
        foreach ($otherTimeIntervals as $otherMetricsTimeIntervals) {
            foreach ($otherMetricsTimeIntervals->intervals as $otherTimeInterval) {
                $joinedTimeIntervals->intervals[] = $otherTimeInterval;
            }
        }
        return $joinedTimeIntervals;
    }

    /**
     * Every interval ending at `$latestEndUnixSeconds` at the latest
     * ({@see AIConversationMetricsTimeInterval::buildWithEndCappedAt()}).
     *
     * @param float $latestEndUnixSeconds
     * @return static
     */
    public function buildWithEndsCappedAt(float $latestEndUnixSeconds): static
    {
        $cappedTimeIntervals = new static();
        foreach ($this->intervals as $timeInterval) {
            $cappedTimeIntervals->intervals[] = $timeInterval->buildWithEndCappedAt($latestEndUnixSeconds);
        }
        return $cappedTimeIntervals;
    }

    /**
     * The time the intervals cover, as intervals that do not overlap, sorted by start. An open interval and one whose
     * end is not after its start cover nothing and are left out.
     *
     * @return static
     */
    public function buildMerged(): static
    {
        $intervalsWithPositiveLength = array_values(array_filter(
            $this->intervals,
            static fn (AIConversationMetricsTimeInterval $timeInterval): bool => $timeInterval->hasPositiveLength()
        ));
        usort(
            $intervalsWithPositiveLength,
            static fn (AIConversationMetricsTimeInterval $earlier, AIConversationMetricsTimeInterval $later): int => $earlier->startUnixSeconds <=> $later->startUnixSeconds
        );
        $mergedTimeIntervals = new static();
        $lastMergedInterval = null;
        foreach ($intervalsWithPositiveLength as $timeInterval) {
            if ($lastMergedInterval !== null && $timeInterval->startUnixSeconds <= $lastMergedInterval->endUnixSeconds) {
                $lastMergedInterval->endUnixSeconds = max($lastMergedInterval->endUnixSeconds, $timeInterval->endUnixSeconds);
                continue;
            }
            $lastMergedInterval = AIConversationMetricsTimeInterval::fromStartToEnd($timeInterval->startUnixSeconds, $timeInterval->endUnixSeconds);
            $mergedTimeIntervals->intervals[] = $lastMergedInterval;
        }
        return $mergedTimeIntervals;
    }

    /** @return float The seconds the intervals cover together: intervals that overlap count once */
    public function getMergedSeconds(): float
    {
        $mergedSeconds = 0.0;
        foreach ($this->buildMerged()->intervals as $mergedInterval) {
            $mergedSeconds += $mergedInterval->getSeconds();
        }
        return $mergedSeconds;
    }

    /**
     * The seconds this list and the other list cover in common.
     *
     * @param AIConversationMetricsTimeIntervals $otherTimeIntervals
     * @return float
     */
    public function getSecondsOverlappingWith(AIConversationMetricsTimeIntervals $otherTimeIntervals): float
    {
        $mergedIntervals = $this->buildMerged()->intervals;
        $otherMergedIntervals = $otherTimeIntervals->buildMerged()->intervals;
        $overlappingSeconds = 0.0;
        $position = 0;
        $otherPosition = 0;
        while ($position < count($mergedIntervals) && $otherPosition < count($otherMergedIntervals)) {
            $mergedInterval = $mergedIntervals[$position];
            $otherMergedInterval = $otherMergedIntervals[$otherPosition];
            $overlapSeconds = min($mergedInterval->endUnixSeconds, $otherMergedInterval->endUnixSeconds)
                - max($mergedInterval->startUnixSeconds, $otherMergedInterval->startUnixSeconds);
            if ($overlapSeconds > 0) {
                $overlappingSeconds += $overlapSeconds;
            }
            if ($mergedInterval->endUnixSeconds < $otherMergedInterval->endUnixSeconds) {
                $position++;
            } else {
                $otherPosition++;
            }
        }
        return $overlappingSeconds;
    }
}
