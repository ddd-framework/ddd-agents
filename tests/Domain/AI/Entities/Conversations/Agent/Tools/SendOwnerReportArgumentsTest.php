<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportLegacyArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportOutcomeArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The checked `send_owner_report` arguments are value objects: accepted without feedback, the outcome named REPORT. */
class SendOwnerReportArgumentsTest extends UnitTestCase
{
    public function testTheOutcomeArgumentsSayWhetherTheCallIsAcceptedAndAReport(): void
    {
        $report = SendOwnerReportTool::validateOutcomeArguments(['outcome' => 'report', 'subject' => 'New review']);
        self::assertInstanceOf(SendOwnerReportOutcomeArguments::class, $report);
        self::assertTrue($report->isAccepted());
        self::assertTrue($report->isReport());
        self::assertSame('REPORT', $report->outcome);

        $nothing = SendOwnerReportTool::validateOutcomeArguments(['outcome' => 'NOTHING_TO_REPORT']);
        self::assertTrue($nothing->isAccepted());
        self::assertFalse($nothing->isReport());

        $missing = SendOwnerReportTool::validateOutcomeArguments([]);
        self::assertFalse($missing->isAccepted());
        self::assertSame('', $missing->outcome);
    }

    public function testTheLegacyArgumentsCarrySubjectBodyAndFeedback(): void
    {
        $accepted = SendOwnerReportLegacyArguments::forSubjectBodyAndFeedback('Subject', 'Body', []);
        self::assertTrue($accepted->isAccepted());
        self::assertFalse(SendOwnerReportTool::validateArguments(['subject' => 'Subject'])->isAccepted());
    }
}
