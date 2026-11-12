<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionBudgets;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The token-budget derivation — the formula + guards that the admin "How it works" / "Live preview"
 * visualizes. Pure math; no container.
 */
class CompactionBudgetsTest extends UnitTestCase
{
    /** The canonical 128k-model example the admin Live preview shows: 0.6·128k working limit, ~8k overhead. */
    public function testDerivesTheCanonicalLivePreviewBudget(): void
    {
        // workingContextLimit = 0.6 · 128000 = 76800 (the trigger fraction default, resolved by buildCompactionBudgets)
        $budgets = CompactionBudgets::fromMeasured(76800, 8000);

        self::assertFalse($budgets->isDegenerate);
        self::assertSame(76800, $budgets->workingContextLimit);
        self::assertSame(46080, $budgets->compactedTargetTokens);   // 0.60 · 76800
        self::assertSame(7680, $budgets->nextTurnReserveTokens);    // 0.10 · 76800
        self::assertSame(30400, $budgets->historyBudgetTokens);     // 46080 − 8000 − 7680
        self::assertSame(12160, $budgets->rawRecentTokens);         // 0.40 · 30400
        self::assertSame(18240, $budgets->summaryBudgetTokens);     // 30400 − 12160
        self::assertSame(5, $budgets->rollupFanIn);                 // 1 / 0.20 (self-similar)
        self::assertSame(4, $budgets->fineNodesPerLevel);
        self::assertSame(3040, $budgets->leafSegmentTokens);        // max(256, 12160 / 4)
    }

    /** Overhead + reserve eat the whole target → non-positive history → degenerate (caller keeps message-count path). */
    public function testFlagsDegenerateWhenOverheadEatsTheBudget(): void
    {
        $budgets = CompactionBudgets::fromMeasured(5000, 4000);

        self::assertTrue($budgets->isDegenerate);
        self::assertSame(0, $budgets->historyBudgetTokens);
        self::assertSame(CompactionBudgets::MIN_LEAF_SEGMENT_TOKENS, $budgets->leafSegmentTokens);
    }

    /** A small-but-positive history below the fine-node floor is still degenerate (don't divide nonsense). */
    public function testFlagsDegenerateBelowTheFineNodeFloor(): void
    {
        // compactedTarget = 0.6·18000 = 10800; reserve = 1800; history = 10800 − 8000 − 1800 = 1000 < 4·256 = 1024
        $budgets = CompactionBudgets::fromMeasured(18000, 8000);

        self::assertTrue($budgets->isDegenerate);
        self::assertSame(1000, $budgets->historyBudgetTokens);
    }

    /** The debug override forces the leaf span and bypasses the degenerate floor (deep-tree test knob). */
    public function testDebugOverrideForcesLeafSegmentAndSkipsTheFloor(): void
    {
        $budgets = CompactionBudgets::fromMeasured(76800, 8000, 500);

        self::assertFalse($budgets->isDegenerate);
        self::assertSame(500, $budgets->leafSegmentTokens);
    }

    /** A zero working limit (unresolved window AND no absolute trigger) collapses cleanly to degenerate, never throws. */
    public function testZeroWorkingLimitIsDegenerateNotFatal(): void
    {
        $budgets = CompactionBudgets::fromMeasured(0, 0);

        self::assertTrue($budgets->isDegenerate);
        self::assertSame(0, $budgets->workingContextLimit);
        self::assertSame(0, $budgets->historyBudgetTokens);
    }
}
