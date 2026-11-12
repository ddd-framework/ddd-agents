<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Recorded-run comparison: the sub-agent texts carry the wall-clock duration of a run ("Sub-agent #1
 * «label» COMPLETED (run 1, 13 s). Result: …"); a replay of the same run took 1 s and read as a difference in the
 * stored message and in every later model request. The durations those texts render are a placeholder; any
 * other duration stays raw.
 */
class AgentRecordedRunSubAgentDurationTest extends UnitTestCase
{
    public function testTheRunEndNotificationReadsTheSameWhateverTheRunTook(): void
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        $recorded = $idNormalization->normalizeText("Sub-agent #1 «Competitor scan» COMPLETED (run 1, 13 s). Result:\nThree competitors found.\nStill running: #2 «Keyword ideas» (2 min), #3 «Reviews» (queued).");
        $replayed = $idNormalization->normalizeText("Sub-agent #1 «Competitor scan» COMPLETED (run 1, 1 s). Result:\nThree competitors found.\nStill running: #2 «Keyword ideas» (" . DelegationSubAgentRun::formatDuration(5) . '), #3 «Reviews» (queued).');

        self::assertSame("Sub-agent #1 «Competitor scan» COMPLETED (run 1, <duration>). Result:\nThree competitors found.\nStill running: #2 «Keyword ideas» (<duration>), #3 «Reviews» (queued).", $recorded);
        self::assertSame($recorded, $replayed);
    }

    public function testTheStatusReportRowsAndLiveStepsReadTheSameWhateverTheRunTook(): void
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        $recordedRows = "#1 «Competitor scan»: COMPLETED 3 min ago (run 1, 13 s)\n#2 «Keyword ideas»: RUNNING for 40 s (run 2) — 9 steps, last step 20 s ago: getWebPageAsMarkdown\n  run 1: COMPLETED, ran 1 h\n  run 2: RUNNING, for 40 s\n  - getWebPageAsMarkdown (COMPLETED, 20 s ago)\n  run 3: NOT RUN, ran 2 s";
        $replayedRows = "#1 «Competitor scan»: COMPLETED 0 s ago (run 1, 1 s)\n#2 «Keyword ideas»: RUNNING for 2 s (run 2) — 9 steps, last step 1 s ago: getWebPageAsMarkdown\n  run 1: COMPLETED, ran 1 s\n  run 2: RUNNING, for 2 s\n  - getWebPageAsMarkdown (COMPLETED, 0 s ago)\n  run 3: NOT RUN, ran 5 s";

        self::assertSame($idNormalization->normalizeText($recordedRows), $idNormalization->normalizeText($replayedRows));
        self::assertStringContainsString('COMPLETED <duration> ago (run 1, <duration>)', $idNormalization->normalizeText($recordedRows));
        self::assertStringContainsString('RUNNING for <duration> (run 2)', $idNormalization->normalizeText($recordedRows));
        self::assertStringContainsString('run 1: COMPLETED, ran <duration>', $idNormalization->normalizeText($recordedRows));
    }

    public function testTheDurationsInsideAModelRequestBodyReadTheSame(): void
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        $recordedBody = (string)json_encode(['messages' => [['role' => 'user', 'content' => '[SIGNAL:delegation] Sub-agent #1 «Scan» COMPLETED (run 1, 13 s). Result: done']]], JSON_UNESCAPED_UNICODE);
        $replayedBody = (string)json_encode(['messages' => [['role' => 'user', 'content' => '[SIGNAL:delegation] Sub-agent #1 «Scan» COMPLETED (run 1, 1 s). Result: done']]], JSON_UNESCAPED_UNICODE);

        self::assertSame($idNormalization->normalizeJsonOrText($recordedBody), $idNormalization->normalizeJsonOrText($replayedBody));
    }

    public function testADurationOutsideTheSubAgentTextsStaysRaw(): void
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        $proseAndToolResults = 'The page loads in 13 s; delivery takes 2 h (run 1 of the courier); open for 30 min (daily); a run of 5 min.';

        self::assertSame($proseAndToolResults, $idNormalization->normalizeText($proseAndToolResults));
        self::assertSame('Sub-agent #1 «Scan» COMPLETED (run 1, 13 seconds).', $idNormalization->normalizeText('Sub-agent #1 «Scan» COMPLETED (run 1, 13 seconds).'), 'only the units formatDuration writes');
    }
}
