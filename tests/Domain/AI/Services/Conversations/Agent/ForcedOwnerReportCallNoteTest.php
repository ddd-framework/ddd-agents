<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The note before the forced `send_owner_report` step asks for the outcome and names both outcomes on equal terms: a
 * final text that says the check found nothing is closed with NOTHING_TO_REPORT, and the note still states which
 * message REPORT sends.
 */
class ForcedOwnerReportCallNoteTest extends UnitTestCase
{
    public function testTheNoteAsksForTheOutcomeBeforeItSaysWhatReportSends(): void
    {
        $note = AIConversationOwnerReportDeliveryService::FORCED_OWNER_REPORT_CALL_NOTE;

        self::assertStringStartsWith('State the outcome of this run now; your previous message decides it.', $note);
        $nothingToReportPosition = strpos($note, SendOwnerReportTool::OUTCOME_NOTHING_TO_REPORT . ' when that message says');
        $reportPosition = strpos($note, SendOwnerReportTool::OUTCOME_REPORT . ' when that message carries');
        self::assertNotFalse($nothingToReportPosition);
        self::assertNotFalse($reportPosition);
        self::assertLessThan($reportPosition, $nothingToReportPosition, 'the outcome without an email is named first');
        self::assertStringNotContainsString('IS the report', $note, 'the note does not declare the previous message a report before the outcome is stated');
    }

    public function testTheNoteStillStatesWhichMessageReportSends(): void
    {
        self::assertStringEndsWith(
            'REPORT emails exactly that message, give only the subject.',
            AIConversationOwnerReportDeliveryService::FORCED_OWNER_REPORT_CALL_NOTE
        );
        self::assertStringContainsString('a summary, an overview or a draft the task asked for', AIConversationOwnerReportDeliveryService::FORCED_OWNER_REPORT_CALL_NOTE);
    }

    public function testAProblemThatKeptTheTaskFromBeingDoneIsReported(): void
    {
        $note = AIConversationOwnerReportDeliveryService::FORCED_OWNER_REPORT_CALL_NOTE;
        $reportPosition = strpos($note, SendOwnerReportTool::OUTCOME_REPORT . ' when that message carries');
        $problemPosition = strpos($note, 'or a problem that kept the task from being done');
        self::assertNotFalse($problemPosition);
        self::assertGreaterThan($reportPosition, $problemPosition, 'the problem is named under REPORT');
    }
}
