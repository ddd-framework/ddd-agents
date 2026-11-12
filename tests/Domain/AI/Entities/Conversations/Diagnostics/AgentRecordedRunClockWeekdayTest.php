<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Recorded-run comparison: the clock signal names the weekday beside its timestamp ("now = 2026-10-19 21:04 (Monday)");
 * a replay on the next day read as a difference on every model call. The weekday beside a
 * timestamp or a date is a placeholder like the timestamp; a weekday in prose stays.
 */
class AgentRecordedRunClockWeekdayTest extends UnitTestCase
{
    public function testTheWeekdayBesideTheClockMovesWithTheReplayDay(): void
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        $recorded = $idNormalization->normalizeText('[SIGNAL:clock] now = 2026-10-19 21:04:11 (Monday)');
        $replayed = $idNormalization->normalizeText('[SIGNAL:clock] now = 2026-10-20 00:58:02 (Tuesday)');

        self::assertSame('[SIGNAL:clock] now = <timestamp> (<weekday>)', $recorded);
        self::assertSame($recorded, $replayed);
        self::assertSame('open on Monday and Tuesday', $idNormalization->normalizeText('open on Monday and Tuesday'), 'prose keeps its weekdays');
    }
}
