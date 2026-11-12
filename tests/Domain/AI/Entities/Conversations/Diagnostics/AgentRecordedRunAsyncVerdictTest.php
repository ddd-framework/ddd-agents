<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The tool-result injection screening runs in a worker after the turn wrote the call row: a recording and its replay
 * may read the row before or after that job, so the verdict keys never decide the comparison.
 */
class AgentRecordedRunAsyncVerdictTest extends UnitTestCase
{
    public function testAPendingAndACleanScreeningVerdictNormalizeAlike(): void
    {
        $normalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        $recorded = '{"name":"getWebPageAsMarkdown","status":"COMPLETED","injectionScreening":"clean","injectionProbability":0.04,"result":"page"}';
        $replayed = '{"name":"getWebPageAsMarkdown","status":"COMPLETED","injectionScreening":"pending","result":"page"}';

        $this->assertSame($normalization->normalizeJsonOrText($recorded), $normalization->normalizeJsonOrText($replayed));
    }

    public function testTheResultItselfStillDecides(): void
    {
        $normalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        $recorded = '{"name":"getWebPageAsMarkdown","injectionScreening":"clean","result":"page"}';
        $replayed = '{"name":"getWebPageAsMarkdown","injectionScreening":"removed","result":"[removed]"}';

        $this->assertNotSame($normalization->normalizeJsonOrText($recorded), $normalization->normalizeJsonOrText($replayed));
    }
}
