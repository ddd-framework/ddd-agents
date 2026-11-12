<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `send_owner_report` argument validation: the outcome contract (REPORT with a one-line subject,
 * NOTHING_TO_REPORT without one), strict + corrective (cap-and-tell, never silent truncation); the legacy
 * subject+body validator stays for persisted pre-cutover calls. Pure static logic.
 */
class SendOwnerReportToolTest extends UnitTestCase
{
    public function testSchemaOffersTheTwoOutcomesAndRequiresOnlyTheOutcome(): void
    {
        $schema = SendOwnerReportTool::create()->toOpenAiToolSchema()['function']['parameters'];
        self::assertSame(['outcome'], $schema['required']);
        self::assertSame(['outcome', 'subject'], array_keys($schema['properties']));
        self::assertSame(['REPORT', 'NOTHING_TO_REPORT'], $schema['properties']['outcome']['enum']);
    }

    public function testReportNeedsASubjectAndNothingToReportRefusesOne(): void
    {
        $report = SendOwnerReportTool::validateOutcomeArguments(['outcome' => 'REPORT', 'subject' => "Weekly check:\nall good"]);
        self::assertSame([], $report->feedback);
        self::assertSame('Weekly check: all good', $report->subject, 'newlines collapse — a subject is ONE line');

        $reportWithoutSubject = SendOwnerReportTool::validateOutcomeArguments(['outcome' => 'REPORT']);
        self::assertCount(1, $reportWithoutSubject->feedback);
        self::assertStringContainsString("'subject'", $reportWithoutSubject->feedback[0]);

        $nothing = SendOwnerReportTool::validateOutcomeArguments(['outcome' => 'nothing_to_report']);
        self::assertSame([], $nothing->feedback);
        self::assertSame(SendOwnerReportTool::OUTCOME_NOTHING_TO_REPORT, $nothing->outcome, 'the outcome is normalized to upper case');

        $nothingWithSubject = SendOwnerReportTool::validateOutcomeArguments(['outcome' => 'NOTHING_TO_REPORT', 'subject' => 'No new mentions']);
        self::assertCount(1, $nothingWithSubject->feedback);
        self::assertStringContainsString('takes no subject', $nothingWithSubject->feedback[0]);
    }

    public function testUnknownOutcomeAndExtraArgumentsAreRejectedWithCorrectiveFeedback(): void
    {
        $missing = SendOwnerReportTool::validateOutcomeArguments(['subject' => 'Report']);
        self::assertCount(1, $missing->feedback, 'no outcome: ONE corrective line; a subject without an outcome is not judged on its own');
        self::assertStringContainsString("'outcome'", $missing->feedback[0]);

        $extra = SendOwnerReportTool::validateOutcomeArguments(['outcome' => 'REPORT', 'subject' => 'Report', 'body' => 'Duplicate']);
        self::assertCount(1, $extra->feedback);
        self::assertStringContainsString('Supply only outcome and subject', $extra->feedback[0]);
    }

    public function testValidArgumentsPassAndNormalize(): void
    {
        $validated = SendOwnerReportTool::validateArguments([
            'subject' => "Weekly check:\nall good",
            'body' => "## Report\nEverything fine.",
        ]);
        $this->assertSame([], $validated->feedback);
        $this->assertSame('Weekly check: all good', $validated->subject, 'newlines collapse — a subject is ONE line');
        $this->assertSame("## Report\nEverything fine.", $validated->body, 'the body keeps its newlines untouched');
    }

    public function testAliasKeysAreAccepted(): void
    {
        $validated = SendOwnerReportTool::validateArguments(['title' => 'Outcome', 'report' => 'The findings.']);
        $this->assertSame([], $validated->feedback);
        $this->assertSame('Outcome', $validated->subject);
        $this->assertSame('The findings.', $validated->body);
    }

    public function testMissingSubjectAndBodyAreRejectedWithCorrectiveFeedback(): void
    {
        $validated = SendOwnerReportTool::validateArguments([]);
        $this->assertCount(2, $validated->feedback);
        $this->assertStringContainsString("'subject'", $validated->feedback[0]);
        $this->assertStringContainsString("'body'", $validated->feedback[1]);
    }

    public function testOverlongSubjectIsRejectedNamingTheCap(): void
    {
        $validated = SendOwnerReportTool::validateArguments([
            'subject' => str_repeat('x', SendOwnerReportTool::SUBJECT_MAX_CHARS + 1),
            'body' => 'ok',
        ]);
        $this->assertCount(1, $validated->feedback);
        $this->assertStringContainsString((string)SendOwnerReportTool::SUBJECT_MAX_CHARS, $validated->feedback[0], 'cap-and-tell: the limit is named');
    }

    public function testOverlongBodyIsRejectedNamingTheCap(): void
    {
        $validated = SendOwnerReportTool::validateArguments([
            'subject' => 'ok',
            'body' => str_repeat('x', SendOwnerReportTool::BODY_MAX_CHARS + 1),
        ]);
        $this->assertCount(1, $validated->feedback);
        $this->assertStringContainsString((string)SendOwnerReportTool::BODY_MAX_CHARS, $validated->feedback[0]);
    }
}
