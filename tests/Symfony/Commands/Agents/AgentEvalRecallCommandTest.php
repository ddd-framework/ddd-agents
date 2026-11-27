<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Symfony\Commands\Agents\AgentEvalConsoleCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The cross-conversation recall evaluation: one line per case (pass, fail with the missing mentions, not applicable,
 * error), the overall line, the exit code (a failure unless every case that counts passed), the result file a suite
 * reads (with the model, the flavor and the rows' message traces) and the printed JSON (without the model and the
 * flavor).
 *
 * The expected lines are the evaluation's line formats applied to the test's cases (`PASS|FAIL <id>
 * seeded=[<ids>][ (kept)]  tools=[<tools>]  mentions: all found|MISSING <a> | <b>[  ERROR: <message>]`,
 * `N/A  <id>  <detail>`, `  answer: <answer>`, `Overall: <passed>/<counted> (<percent>%)[ · <n> not applicable on
 * their <scope> (not counted)]`); the expected key lists are the array literals of the two documents and of the
 * not-applicable row, copied key by key.
 */
class AgentEvalRecallCommandTest extends IntegrationTestCase
{
    protected string $workDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDirectory = sys_get_temp_dir() . '/agent-eval-recall-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->workDirectory . '/fixtures', 0775, true);
        file_put_contents($this->workDirectory . '/fixtures/a.json', (string)json_encode(['cases' => [
            ['id' => 'recall.pass', 'agentSpaceId' => 7, 'prompt' => 'What was my invoice?', 'expect' => ['tool' => ['searchConversations', 'listItems']], 'expectMentions' => ['4711']],
            ['id' => 'recall.miss', 'agentSpaceId' => 7, 'prompt' => 'Ask', 'expect' => ['tool' => ['searchConversations']], 'expectMentions' => ['invoice', '9999']],
            ['prompt' => 'A case without an id is no recall case'],
        ]]));
        file_put_contents($this->workDirectory . '/fixtures/b.json', (string)json_encode(['cases' => [
            ['id' => 'recall.not-applicable', 'agentSpaceId' => 3, 'prompt' => 'Nothing'],
            ['id' => 'recall.error', 'agentSpaceId' => 7, 'prompt' => 'Break'],
        ]]));
    }

    protected function onTearDown(): void
    {
        MCPToolExecutorService::$everyWriteToolBlocked = false;
        foreach (['/fixtures/a.json', '/fixtures/b.json', '/out/result.json'] as $writtenFile) {
            @unlink($this->workDirectory . $writtenFile);
        }
        @rmdir($this->workDirectory . '/fixtures');
        @rmdir($this->workDirectory . '/out');
        @rmdir($this->workDirectory);
    }

    public function testEveryCasePrintsItsLineAndAFailedCaseFailsTheRun(): void
    {
        $probeCommand = $this->buildProbeCommand();

        $commandTester = $this->runProbeCommand($probeCommand, ['--full' => true, '--model' => 'VENDOR.MODEL']);

        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        self::assertSame(
            "PASS recall.pass  seeded=[11,12]  tools=[listItems]  mentions: all found\n"
            . "  answer: Your invoice number is 4711.\n"
            . "FAIL recall.miss  seeded=[11,12]  tools=[listItems]  mentions: MISSING 9999\n"
            . "  answer: Your invoice number is 4711.\n"
            . "N/A  recall.not-applicable  the seed of the case failed\n"
            . "FAIL recall.error  seeded=[11,12]  tools=[]  mentions: all found  ERROR: the model did not answer\n"
            . "\n"
            . "Overall: 1/3 (33.3%) · 1 not applicable on their " . AgentApplicationWording::current()->agentSpaceName . " (not counted)\n",
            $commandTester->getDisplay()
        );
        self::assertSame(['What was my invoice?', 'Ask', 'Break'], $probeCommand->runnerServiceDouble->ranPrompts);
        self::assertSame(array_fill(0, 3, 'VENDOR.MODEL|persist=0|writesBlocked=1|keepSeed=0'), $probeCommand->runnerServiceDouble->runSettingsOfRuns);
        self::assertTrue(MCPToolExecutorService::$everyWriteToolBlocked);
    }

    public function testACaseFilterAndKeptSeedsAndAPassingRunSucceeds(): void
    {
        $probeCommand = $this->buildProbeCommand();

        $commandTester = $this->runProbeCommand($probeCommand, ['--case' => 'recall.pass', '--keep-seed' => true]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertSame(
            "PASS recall.pass  seeded=[11,12] (kept)  tools=[listItems]  mentions: all found\n\nOverall: 1/1 (100.0%)\n",
            $commandTester->getDisplay()
        );
        self::assertSame(['default|persist=0|writesBlocked=1|keepSeed=1'], $probeCommand->runnerServiceDouble->runSettingsOfRuns);
    }

    public function testTheResultFileCarriesModelFlavorAndTracesThePrintedJsonNeither(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $resultFile = $this->workDirectory . '/out/result.json';

        $commandTester = $this->runProbeCommand($probeCommand, ['--json' => true, '--result-file' => $resultFile, '--model' => 'VENDOR.MODEL', '--fixture' => $this->workDirectory . '/fixtures/b.json']);

        $resultDocument = json_decode((string)file_get_contents($resultFile), true);
        self::assertSame(['passed', 'total', 'notApplicable', 'model', 'flavor', 'cases'], array_keys($resultDocument));
        self::assertSame([0, 1, 1, 'VENDOR.MODEL', 'call'], array_slice(array_values($resultDocument), 0, 5));
        self::assertSame(
            ['id' => 'recall.not-applicable', 'flavor' => 'call', 'agentSpaceId' => 3, 'passed' => null, 'outcome' => 'notApplicable', 'notApplicableReason' => 'preconditionNotEstablished', 'notApplicableDetail' => 'the seed of the case failed'],
            $resultDocument['cases'][0]
        );
        self::assertSame(
            ['id', 'case', 'passed', 'failures', 'performance', 'durationMs', 'totalTokens', 'costUsd', 'seededConversationIds', 'calledTools', 'toolOk', 'missingMentions', 'leakedMentions', 'finalText', 'error', 'messages'],
            array_keys($resultDocument['cases'][1])
        );
        self::assertSame('the model did not answer', $resultDocument['cases'][1]['error']);
        self::assertSame(
            (string)json_encode($resultDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (string)file_get_contents($resultFile)
        );

        $printedDocument = json_decode($commandTester->getDisplay(), true);
        self::assertSame(['passed', 'total', 'notApplicable', 'cases'], array_keys($printedDocument));
        self::assertSame($resultDocument['cases'], $printedDocument['cases']);
    }

    public function testAPassedRowCarriesTheTraceOfItsConversationAndANullError(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $resultFile = $this->workDirectory . '/out/result.json';

        $this->runProbeCommand($probeCommand, ['--json' => true, '--result-file' => $resultFile, '--case' => 'recall.pass']);

        $passedRow = json_decode((string)file_get_contents($resultFile), true)['cases'][0];
        self::assertTrue($passedRow['passed']);
        self::assertTrue($passedRow['toolOk']);
        self::assertNull($passedRow['error'], 'the row keeps `error: null`');
        self::assertSame([11, 12], $passedRow['seededConversationIds']);
        self::assertSame(['elements' => [
            ['messageType' => 'TEXT', 'message' => 'What was my invoice?'],
            ['messageType' => 'TOOL_CALL', 'message' => ''],
            ['messageType' => 'TEXT', 'message' => 'Your invoice number is 4711.'],
        ]], $passedRow['messages']);
    }

    public function testNoCaseFails(): void
    {
        $commandTester = $this->runProbeCommand($this->buildProbeCommand(), ['--case' => 'missing']);

        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        self::assertSame("No recall eval cases found.\n", $commandTester->getDisplay());
        self::assertInstanceOf(AgentEvalConsoleCommand::class, $this->buildProbeCommand());
        self::assertTrue($this->buildProbeCommand()->getDefinition()->hasOption('ignore-budget'));
    }

    /** @param array<string, mixed> $commandInput */
    protected function runProbeCommand(AgentEvalRecallProbeCommand $probeCommand, array $commandInput): CommandTester
    {
        $probeCommand->planServiceDouble->fixturePathsByName = ['recall' => $this->workDirectory . '/fixtures'];
        $probeCommand->runnerServiceDouble->notApplicableCaseIds = ['recall.not-applicable'];
        $probeCommand->runnerServiceDouble->throwingPrompts = ['Break'];
        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute($commandInput, ['capture_stderr_separately' => true]);
        return $commandTester;
    }

    protected function buildProbeCommand(): AgentEvalRecallProbeCommand
    {
        return new AgentEvalRecallProbeCommand();
    }
}
