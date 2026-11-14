<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\AgentConsoleProgressReporter;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The progress reporter prints one line per report, with its prefix and style, prints the reported text as text, and
 * drops every line without an output.
 */
class AgentConsoleProgressReporterTest extends UnitTestCase
{
    public function testEveryReportIsOneLineWithThePrefix(): void
    {
        $bufferedOutput = new BufferedOutput();
        $agentConsoleProgressReporter = AgentConsoleProgressReporter::forOutput($bufferedOutput, '  · ');

        $agentConsoleProgressReporter->reportProgress('window 1 of 3');
        $agentConsoleProgressReporter->reportProgress('window 2 of 3');

        self::assertSame("  · window 1 of 3\n  · window 2 of 3\n", $bufferedOutput->fetch());
    }

    public function testAStyleTagIsAppliedAndTheReportedTextIsNotReadAsATag(): void
    {
        $bufferedOutput = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        AgentConsoleProgressReporter::forOutput($bufferedOutput, '', 'comment')->reportProgress('a <error> in the text');

        $printedText = $bufferedOutput->fetch();
        self::assertStringContainsString('a <error> in the text', $printedText);
        self::assertStringContainsString("\033[", $printedText, 'the comment style is applied');
    }

    public function testAReporterWithoutOutputDropsTheLines(): void
    {
        AgentConsoleProgressReporter::withoutOutput()->reportProgress('dropped');
        $this->addToAssertionCount(1);
    }
}
