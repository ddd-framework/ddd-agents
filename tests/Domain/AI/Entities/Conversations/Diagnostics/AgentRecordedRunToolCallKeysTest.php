<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Recorded-run comparison: a stored tool call that gained a key after the recording (`effect`) is
 * compared without it while the recording does not hold it — and with it once the recording does.
 */
class AgentRecordedRunToolCallKeysTest extends UnitTestCase
{
    public function testAKeyTheRecordingPredatesIsLeftOut(): void
    {
        $recorded = '{"name":"getProfileSummary","status":"COMPLETED"}';
        $replayed = '{"name":"getProfileSummary","status":"COMPLETED","effect":{"effect":"READ"}}';

        self::assertSame($recorded, (new \ReflectionMethod(AgentRecordedRunComparison::class, 'withoutToolCallKeysTheRecordingPredates'))->invoke(null, $recorded, $replayed));
        $recordedWithEffect = '{"name":"getProfileSummary","status":"COMPLETED","effect":{"effect":"WRITE"}}';
        self::assertSame($replayed, (new \ReflectionMethod(AgentRecordedRunComparison::class, 'withoutToolCallKeysTheRecordingPredates'))->invoke(null, $recordedWithEffect, $replayed), 'a recording that holds the key compares it');
    }
}
