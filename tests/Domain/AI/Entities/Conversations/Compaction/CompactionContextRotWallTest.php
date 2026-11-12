<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\AI\Entities\Conversations\Compaction\CompactionContextRotWall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The context-rot wall: 80 % of the model's hard window, truncated; no wall without a positive window.
 * Pure math; no container.
 */
class CompactionContextRotWallTest extends UnitTestCase
{
    public function testCapsAtEightyPercentOfTheWindowTruncated(): void
    {
        self::assertSame(26666, CompactionContextRotWall::forContextWindowTokens(33333)->capTokens(100000));
        self::assertSame(160000, CompactionContextRotWall::forContextWindowTokens(200000)->capTokens(1000000));
        self::assertSame(0, CompactionContextRotWall::forContextWindowTokens(1)->capTokens(5));
        self::assertSame(5, CompactionContextRotWall::forContextWindowTokens(7)->capTokens(100));
    }

    public function testKeepsTokensBelowTheWall(): void
    {
        self::assertSame(25600, CompactionContextRotWall::forContextWindowTokens(200000)->capTokens(25600));
        self::assertSame(160000, CompactionContextRotWall::forContextWindowTokens(200000)->capTokens(160000));
    }

    public function testHasNoWallWithoutAPositiveWindow(): void
    {
        foreach ([null, 0, -1] as $contextWindowTokens) {
            $compactionContextRotWall = CompactionContextRotWall::forContextWindowTokens($contextWindowTokens);
            self::assertFalse($compactionContextRotWall->hasWindow());
            self::assertSame(1000000, $compactionContextRotWall->capTokens(1000000));
            self::assertSame(0, $compactionContextRotWall->capTokens(0));
        }
    }

    /** The rule as each of the three sites wrote it inline before b6, over a grid of windows × token counts. */
    public function testMatchesTheInlineRuleOverAGrid(): void
    {
        foreach ([null, 0, -1, 1, 7, 32000, 33333, 125001, 200000, 1050000] as $window) {
            foreach ([0, 1, 20000, 25600, 26666, 99999, 100000, 128000, 1000000] as $tokens) {
                $expected = ($window !== null && $window > 0) ? min($tokens, (int)($window * 0.8)) : $tokens;
                self::assertSame($expected, CompactionContextRotWall::forContextWindowTokens($window)->capTokens($tokens));
            }
        }
    }
}
