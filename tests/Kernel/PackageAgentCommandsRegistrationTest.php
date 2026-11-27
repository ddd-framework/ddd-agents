<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Symfony\Commands\Agents\AgentConnectionsSeedFakeCommand;
use DDD\Symfony\Commands\Agents\AgentConnectionsSlackSocketModeCommand;
use DDD\Symfony\Commands\Agents\AgentConnectionsSweepCommand;
use DDD\Symfony\Commands\Agents\AgentActivityLinesCommand;
use DDD\Symfony\Commands\Agents\AgentCacheDiffCommand;
use DDD\Symfony\Commands\Agents\AgentChatCommand;
use DDD\Symfony\Commands\Agents\AgentCheckInstallationCommand;
use DDD\Symfony\Commands\Agents\AgentChatTuiCommand;
use DDD\Symfony\Commands\Agents\AgentCloseSchedulesForInactiveAgentSpacesCommand;
use DDD\Symfony\Commands\Agents\AgentCompactConversationCommand;
use DDD\Symfony\Commands\Agents\AgentCompactFinalizedConversationsCommand;
use DDD\Symfony\Commands\Agents\AgentDecisionFixturesCommand;
use DDD\Symfony\Commands\Agents\AgentDecisionStatsCommand;
use DDD\Symfony\Commands\Agents\AgentDeliverOwedReportsAndResumeOwedTurnsCommand;
use DDD\Symfony\Commands\Agents\AgentDiagnoseResumeCommand;
use DDD\Symfony\Commands\Agents\AgentEvalAnalyzeCommand;
use DDD\Symfony\Commands\Agents\AgentEvalCompactionTreeCommand;
use DDD\Symfony\Commands\Agents\AgentEvalConversationsPurgeCommand;
use DDD\Symfony\Commands\Agents\AgentEvalEvictionCommand;
use DDD\Symfony\Commands\Agents\AgentEvalGuardCommand;
use DDD\Symfony\Commands\Agents\AgentEvalRecallCommand;
use DDD\Symfony\Commands\Agents\AgentEvalScheduleRunsCommand;
use DDD\Symfony\Commands\Agents\AgentEvalSelectionCommand;
use DDD\Symfony\Commands\Agents\AgentEvalSuiteCommand;
use DDD\Symfony\Commands\Agents\AgentEvalTiersCommand;
use DDD\Symfony\Commands\Agents\AgentEvalToolsCommand;
use DDD\Symfony\Commands\Agents\AgentExecuteToolCallCommand;
use DDD\Symfony\Commands\Agents\AgentFireDueSchedulesCommand;
use DDD\Symfony\Commands\Agents\AgentInspectConversationCommand;
use DDD\Symfony\Commands\Agents\AgentJevProbeCommand;
use DDD\Symfony\Commands\Agents\AgentProbeReasoningReplayCommand;
use DDD\Symfony\Commands\Agents\AgentLoadedCapabilitiesCompactCommand;
use DDD\Symfony\Commands\Agents\AgentMarkFinalizedConversationsCommand;
use DDD\Symfony\Commands\Agents\AgentModelInputEvictionSimCommand;
use DDD\Symfony\Commands\Agents\AgentReapStuckConversationsCommand;
use DDD\Symfony\Commands\Agents\AgentRequestSnapshotDebugPayloadsStripCommand;
use DDD\Symfony\Commands\Agents\AgentToolResponseStatsCommand;
use DDD\Symfony\Commands\Agents\AgentTraceEventsPurgeCommand;
use DDD\Symfony\Commands\Agents\AgentTurnEventsPurgeCommand;
use DDD\Symfony\Commands\Agents\AgentRunReportCommand;
use DDD\Symfony\Commands\Agents\AgentValidateConversationCommand;
use DDD\Symfony\Commands\Agents\AgentVoiceSamplesGenerateCommand;
use DDD\Symfony\Commands\Agents\AIConversationBackfillEmbeddingsCommand;
use DDD\Symfony\Commands\Agents\AIConversationBackfillEnvironmentCommand;
use DDD\Symfony\Commands\Agents\AIConversationEmbeddingsRepairCommand;
use DDD\Symfony\Commands\Agents\AIConversationScorecardsCommand;
use DDD\Symfony\Commands\Agents\AISearchInspectCommand;
use DDD\Symfony\Commands\Agents\Chat\SlashCommandRegistry;
use DDD\Symfony\Commands\Agents\ComposedViewNodeSchemaExportCommand;
use DDD\Symfony\Commands\Agents\MCPClearCacheCommand;
use DDD\Symfony\Commands\Agents\MCPCompareToolCallsCommand;
use DDD\Symfony\Commands\Agents\MCPDocumentationCommand;
use DDD\Symfony\Commands\Agents\SkillTokenMapCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * The package kernel registers every plain agent command of the module under the name production cron rows and
 * scripts call it by, the package's console script lists every one but the hidden tool-call child and finds that one
 * by name, and the chat helpers are no services.
 */
class PackageAgentCommandsRegistrationTest extends IntegrationTestCase
{
    /** @var string[] The command class by command name */
    public const array COMMAND_CLASSES_BY_NAME = [
        'app:agent:backfill-message-embeddings' => AIConversationBackfillEmbeddingsCommand::class,
        'app:agent:repair-zero-embeddings' => AIConversationEmbeddingsRepairCommand::class,
        'app:agent:backfill-conversation-environment' => AIConversationBackfillEnvironmentCommand::class,
        'app:agent:conversation-search-inspect' => AISearchInspectCommand::class,
        'app:agent:activity-lines' => AgentActivityLinesCommand::class,
        'app:agent:cache-diff' => AgentCacheDiffCommand::class,
        'app:agent:compact' => AgentCompactConversationCommand::class,
        'app:agent:compact-finalized-conversations' => AgentCompactFinalizedConversationsCommand::class,
        'app:agent:diagnose' => AgentDiagnoseResumeCommand::class,
        'app:agent:execute-tool-call' => AgentExecuteToolCallCommand::class,
        'app:agent:fire-due-schedules' => AgentFireDueSchedulesCommand::class,
        'app:agent:loaded-capabilities-compact' => AgentLoadedCapabilitiesCompactCommand::class,
        'app:agent:mark-finalized-conversations' => AgentMarkFinalizedConversationsCommand::class,
        'app:agent:context-eviction-sim' => AgentModelInputEvictionSimCommand::class,
        'app:agent:request-snapshot-debug-payloads-strip' => AgentRequestSnapshotDebugPayloadsStripCommand::class,
        'app:agent:tool-response-stats' => AgentToolResponseStatsCommand::class,
        'app:agent:trace-events-purge' => AgentTraceEventsPurgeCommand::class,
        'app:agent:turn-events-purge' => AgentTurnEventsPurgeCommand::class,
        'app:agent:validate-conversation' => AgentValidateConversationCommand::class,
        'app:agent:check-installation' => AgentCheckInstallationCommand::class,
        'app:agent:run-report' => AgentRunReportCommand::class,
        'app:agent:skill-token-map' => SkillTokenMapCommand::class,
        'app:agent:chat' => AgentChatCommand::class,
        'app:agent:chat-tui' => AgentChatTuiCommand::class,
        'app:agent:inspect' => AgentInspectConversationCommand::class,
        'app:agent:reap' => AgentReapStuckConversationsCommand::class,
        'app:agent:jev-probe' => AgentJevProbeCommand::class,
        'app:agent:probe-reasoning-replay' => AgentProbeReasoningReplayCommand::class,
        'app:agent:deliver-owed-reports-and-resume-owed-turns' => AgentDeliverOwedReportsAndResumeOwedTurnsCommand::class,
        'app:agent:conversation-scorecards' => AIConversationScorecardsCommand::class,
        'app:agent:close-schedules-for-inactive-agent-spaces' => AgentCloseSchedulesForInactiveAgentSpacesCommand::class,
        'app:mcp:documentation' => MCPDocumentationCommand::class,
        'app:mcp:clear-cache' => MCPClearCacheCommand::class,
        'app:mcp:compare-tool-calls' => MCPCompareToolCallsCommand::class,
        'app:agent:export-composed-view-node-schema' => ComposedViewNodeSchemaExportCommand::class,
        'app:agent:eval-tools' => AgentEvalToolsCommand::class,
        'app:agent:eval-selection' => AgentEvalSelectionCommand::class,
        'app:agent:eval-recall' => AgentEvalRecallCommand::class,
        'app:agent:eval-tiers' => AgentEvalTiersCommand::class,
        'app:agent:eval-analyze' => AgentEvalAnalyzeCommand::class,
        'app:agent:eval-suite' => AgentEvalSuiteCommand::class,
        'app:agent:eval-eviction' => AgentEvalEvictionCommand::class,
        'app:agent:eval-guard' => AgentEvalGuardCommand::class,
        'app:agent:eval-schedule-runs' => AgentEvalScheduleRunsCommand::class,
        'app:agent:eval-conversations-purge' => AgentEvalConversationsPurgeCommand::class,
        'app:agent:decision-stats' => AgentDecisionStatsCommand::class,
        'app:agent:decision-fixtures' => AgentDecisionFixturesCommand::class,
        'app:agent:eval-compaction-tree' => AgentEvalCompactionTreeCommand::class,
        'app:agent:voice-samples:generate' => AgentVoiceSamplesGenerateCommand::class,
        'app:agent:connections:sweep' => AgentConnectionsSweepCommand::class,
        'app:agent:connections:slack-socket-mode' => AgentConnectionsSlackSocketModeCommand::class,
        'app:agent:connections:seed-fake' => AgentConnectionsSeedFakeCommand::class,
    ];

    /** @var string The tool-call child: run by name, never listed */
    public const string HIDDEN_COMMAND_NAME = 'app:agent:execute-tool-call';

    public function testEveryPlainCommandIsRegisteredUnderItsName(): void
    {
        $application = new Application(static::bootTestKernel());
        foreach (self::COMMAND_CLASSES_BY_NAME as $commandName => $commandClass) {
            $command = $application->find($commandName);
            if ($command instanceof LazyCommand) {
                $command = $command->getCommand();
            }
            self::assertInstanceOf($commandClass, $command, $commandName);
        }
    }

    public function testTheToolCallChildIsHiddenFromTheCommandList(): void
    {
        $application = new Application(static::bootTestKernel());

        // the console's own wrapper decides the list, not the command object behind it
        self::assertTrue($application->find('app:agent:execute-tool-call')->isHidden());
        self::assertArrayNotHasKey('app:agent:execute-tool-call', array_filter($application->all(), static fn ($command): bool => !$command->isHidden()));
    }

    public function testTheConsoleScriptListsEveryVisibleCommandAndFindsTheHiddenOneByName(): void
    {
        $listProcess = $this->runConsoleScript('list', '--format=json');
        self::assertSame(0, $listProcess->getExitCode(), $listProcess->getErrorOutput());
        // the JSON listing carries the hidden commands too, marked; the text listing a maintainer reads leaves them out
        $listedCommandNames = [];
        $hiddenCommandNames = [];
        foreach (json_decode($listProcess->getOutput(), true)['commands'] ?? [] as $listedCommand) {
            if ($listedCommand['hidden'] ?? false) {
                $hiddenCommandNames[] = (string)$listedCommand['name'];
                continue;
            }
            $listedCommandNames[] = (string)$listedCommand['name'];
        }

        $visibleCommandNames = array_keys(self::COMMAND_CLASSES_BY_NAME);
        $visibleCommandNames = array_values(array_diff($visibleCommandNames, [self::HIDDEN_COMMAND_NAME]));
        self::assertCount(51, $visibleCommandNames);
        self::assertSame([], array_values(array_diff($visibleCommandNames, $listedCommandNames)), 'every visible command is listed');
        self::assertNotContains(self::HIDDEN_COMMAND_NAME, $listedCommandNames);
        self::assertContains(self::HIDDEN_COMMAND_NAME, $hiddenCommandNames);
        $textListProcess = $this->runConsoleScript('list', '--raw');
        self::assertSame(51, preg_match_all('/^app:(agent|mcp):\S+/m', $textListProcess->getOutput()), 'the text listing names the 51 visible commands');

        $helpProcess = $this->runConsoleScript('help', self::HIDDEN_COMMAND_NAME);
        self::assertSame(0, $helpProcess->getExitCode(), $helpProcess->getErrorOutput());
        self::assertStringContainsString(self::HIDDEN_COMMAND_NAME, $helpProcess->getOutput());
    }

    public function testTheChatHelpersAreNoServices(): void
    {
        static::bootTestKernel();

        self::assertFalse(static::getContainer()->has(SlashCommandRegistry::class));
    }

    public function testTheEvictionSimulatorPlaysAScenarioThroughTheKernel(): void
    {
        $application = new Application(static::bootTestKernel());
        $commandTester = new CommandTester($application->find('app:agent:context-eviction-sim'));

        self::assertSame(0, $commandTester->execute(['--scenario' => 'flood', '--soft' => '6000', '--hard' => '10000']));
        self::assertStringContainsString('══ scenario: flood ══', $commandTester->getDisplay());
    }

    /**
     * Runs `tests/Support/bin/console.php` in a process of its own, with the environment of this run (the vendor
     * directory included): the console a maintainer types, with its own kernel boot.
     */
    protected function runConsoleScript(string ...$consoleArguments): Process
    {
        $consoleProcess = new Process([PHP_BINARY, dirname(__DIR__) . '/Support/bin/console.php', ...$consoleArguments, '--no-ansi'], dirname(__DIR__, 2));
        $consoleProcess->setTimeout(120);
        $consoleProcess->run();
        return $consoleProcess;
    }
}
