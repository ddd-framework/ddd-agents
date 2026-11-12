<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\AI\Entities\Conversations\Recall\RecallTurnRange;
use PHPUnit\Framework\TestCase;

/**
 * Pure turn-range resolution: a back-reference phrasing → an inclusive indexInConversation
 * window the search filters on ("at the beginning" is a FILTER, not a similarity boost).
 */
class RecallTurnRangeTest extends TestCase
{
    public function testBeginningAndRecentResolveToEdgeWindows(): void
    {
        // 20 turns → edge = ceil(20*0.25) = 5.
        $this->assertRange(0, 5, RecallTurnRange::resolveFromPhrasing('what did we say at the beginning?', 20));
        $this->assertRange(15, 20, RecallTurnRange::resolveFromPhrasing('something you mentioned recently', 20));
    }

    public function testNoTemporalAnchorReturnsNull(): void
    {
        $this->assertNull(RecallTurnRange::resolveFromPhrasing('tell me about the campaign keywords', 20));
    }

    public function testRecentWinsWhenBothMentioned(): void
    {
        // "the latest reference dominates" — recent wins over beginning.
        $this->assertRange(15, 20, RecallTurnRange::resolveFromPhrasing('unlike the beginning, what did we agree recently?', 20));
    }

    public function testShortAndDegenerateConversations(): void
    {
        // 2 turns → edge = max(1, ceil(0.5)) = 1.
        $this->assertRange(0, 1, RecallTurnRange::resolveFromPhrasing('at the start', 2));
        $this->assertRange(1, 2, RecallTurnRange::resolveFromPhrasing('just now', 2));
        // single-message conversation (highest index 0) → edge clamps, no out-of-range.
        $this->assertRange(0, 0, RecallTurnRange::resolveFromPhrasing('at the beginning', 0));
        // negative (no messages) → null.
        $this->assertNull(RecallTurnRange::resolveFromPhrasing('at the beginning', -1));
    }

    /**
     * @param int $expectedMinIndex
     * @param int $expectedMaxIndex
     * @param RecallTurnRange|null $recallTurnRange
     * @return void
     */
    protected function assertRange(int $expectedMinIndex, int $expectedMaxIndex, ?RecallTurnRange $recallTurnRange): void
    {
        $this->assertInstanceOf(RecallTurnRange::class, $recallTurnRange);
        $this->assertSame([$expectedMinIndex, $expectedMaxIndex], [$recallTurnRange->minIndex, $recallTurnRange->maxIndex]);
    }
}
