<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Metrics;

use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetricsTimeInterval;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetricsTimeIntervals;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The interval arithmetic of the conversation metrics: stretches that overlap count once, an open or empty stretch
 * covers nothing, and two lists are compared by the time they cover in common.
 */
class AIConversationMetricsTimeIntervalsTest extends UnitTestCase
{
    public function testOverlappingIntervalsCountOnce(): void
    {
        $timeIntervals = AIConversationMetricsTimeIntervals::forIntervals(
            AIConversationMetricsTimeInterval::fromStartToEnd(0.0, 3.0),
            AIConversationMetricsTimeInterval::fromStartToEnd(2.0, 4.0),
            AIConversationMetricsTimeInterval::fromStartToEnd(10.0, 11.0),
            AIConversationMetricsTimeInterval::fromStartToEnd(7.0, 7.0),
        );

        self::assertSame(5.0, $timeIntervals->getMergedSeconds());
        $mergedIntervals = $timeIntervals->buildMerged()->intervals;
        self::assertCount(2, $mergedIntervals);
        self::assertSame(0.0, $mergedIntervals[0]->startUnixSeconds);
        self::assertSame(4.0, $mergedIntervals[0]->endUnixSeconds);
        self::assertSame(3.0, $timeIntervals->intervals[0]->endUnixSeconds, 'merging leaves the added intervals as they were');
    }

    public function testAnOpenIntervalCoversNothingUntilItsEndIsCapped(): void
    {
        $timeIntervals = new AIConversationMetricsTimeIntervals();
        $timeIntervals->addStartToEnd(5.0, null);
        $timeIntervals->addStartToEnd(1.0, 20.0);

        self::assertSame(19.0, $timeIntervals->getMergedSeconds());
        $cappedTimeIntervals = $timeIntervals->buildWithEndsCappedAt(8.0);
        self::assertSame(8.0, $cappedTimeIntervals->intervals[0]->endUnixSeconds);
        self::assertSame(8.0, $cappedTimeIntervals->intervals[1]->endUnixSeconds);
        self::assertSame(7.0, $cappedTimeIntervals->getMergedSeconds());
        self::assertNull($timeIntervals->intervals[0]->endUnixSeconds);
    }

    public function testTheSecondsTwoListsCoverInCommon(): void
    {
        $workIntervals = AIConversationMetricsTimeIntervals::forIntervals(
            AIConversationMetricsTimeInterval::fromStartToEnd(0.0, 4.0),
            AIConversationMetricsTimeInterval::fromStartToEnd(10.0, 11.0),
        );
        $agentIntervals = AIConversationMetricsTimeIntervals::forIntervals(
            AIConversationMetricsTimeInterval::fromStartToEnd(2.0, 6.0),
            AIConversationMetricsTimeInterval::fromStartToEnd(10.5, 12.0),
        );

        self::assertSame(2.5, $workIntervals->getSecondsOverlappingWith($agentIntervals));
        self::assertSame(2.5, $agentIntervals->getSecondsOverlappingWith($workIntervals));
        self::assertSame(0.0, $workIntervals->getSecondsOverlappingWith(new AIConversationMetricsTimeIntervals()));
    }

    public function testJoiningKeepsEveryIntervalOfEveryList(): void
    {
        $toolIntervals = AIConversationMetricsTimeIntervals::forIntervals(AIConversationMetricsTimeInterval::fromStartToEnd(0.0, 1.0));
        $delegationIntervals = AIConversationMetricsTimeIntervals::forIntervals(AIConversationMetricsTimeInterval::fromStartToEnd(0.5, 2.0));

        $joinedTimeIntervals = (new AIConversationMetricsTimeIntervals())->buildJoinedWith($toolIntervals, $delegationIntervals);

        self::assertSame(2, $joinedTimeIntervals->count());
        self::assertSame(2.0, $joinedTimeIntervals->getMergedSeconds());
        self::assertSame(1, $toolIntervals->count());
    }
}
