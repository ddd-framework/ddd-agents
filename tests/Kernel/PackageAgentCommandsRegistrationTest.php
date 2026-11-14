<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Symfony\Commands\Agents\AgentActivityLinesCommand;
use DDD\Symfony\Commands\Agents\AgentCacheDiffCommand;
use DDD\Symfony\Commands\Agents\AgentCompactConversationCommand;
use DDD\Symfony\Commands\Agents\AgentCompactFinalizedConversationsCommand;
use DDD\Symfony\Commands\Agents\AgentDiagnoseResumeCommand;
use DDD\Symfony\Commands\Agents\AgentExecuteToolCallCommand;
use DDD\Symfony\Commands\Agents\AgentFireDueSchedulesCommand;
use DDD\Symfony\Commands\Agents\AgentLoadedCapabilitiesCompactCommand;
use DDD\Symfony\Commands\Agents\AgentMarkFinalizedConversationsCommand;
use DDD\Symfony\Commands\Agents\AgentModelInputEvictionSimCommand;
use DDD\Symfony\Commands\Agents\AgentRequestSnapshotDebugPayloadsStripCommand;
use DDD\Symfony\Commands\Agents\AgentToolResponseStatsCommand;
use DDD\Symfony\Commands\Agents\AgentTraceEventsPurgeCommand;
use DDD\Symfony\Commands\Agents\AgentTurnEventsPurgeCommand;
use DDD\Symfony\Commands\Agents\AgentValidateConversationCommand;
use DDD\Symfony\Commands\Agents\AIConversationBackfillEmbeddingsCommand;
use DDD\Symfony\Commands\Agents\AIConversationBackfillEnvironmentCommand;
use DDD\Symfony\Commands\Agents\AIConversationEmbeddingsRepairCommand;
use DDD\Symfony\Commands\Agents\AISearchInspectCommand;
use DDD\Symfony\Commands\Agents\Chat\SlashCommandRegistry;
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
        'app:ai-conversation:backfill-message-embeddings' => AIConversationBackfillEmbeddingsCommand::class,
        'app:ai-conversation:repair-zero-embeddings' => AIConversationEmbeddingsRepairCommand::class,
        'app:ai-conversation:backfill-environment' => AIConversationBackfillEnvironmentCommand::class,
        'app:ai-conversation:search-inspect' => AISearchInspectCommand::class,
        'app:ai-agent:activity-lines' => AgentActivityLinesCommand::class,
        'app:ai-agent:cache-diff' => AgentCacheDiffCommand::class,
        'app:ai-agent:compact' => AgentCompactConversationCommand::class,
        'app:ai-conversation:compact-finalized' => AgentCompactFinalizedConversationsCommand::class,
        'app:ai-agent:diagnose' => AgentDiagnoseResumeCommand::class,
        'app:ai-agent:execute-tool-call' => AgentExecuteToolCallCommand::class,
        'app:ai-conversation:fire-due-schedules' => AgentFireDueSchedulesCommand::class,
        'app:ai-agent:loaded-capabilities-compact' => AgentLoadedCapabilitiesCompactCommand::class,
        'app:ai-conversation:mark-finalized' => AgentMarkFinalizedConversationsCommand::class,
        'app:ai-agent:context-eviction-sim' => AgentModelInputEvictionSimCommand::class,
        'app:ai-agent:request-snapshot-debug-payloads-strip' => AgentRequestSnapshotDebugPayloadsStripCommand::class,
        'app:ai-agent:tool-response-stats' => AgentToolResponseStatsCommand::class,
        'app:ai-agent:trace-events-purge' => AgentTraceEventsPurgeCommand::class,
        'app:ai-agent:turn-events-purge' => AgentTurnEventsPurgeCommand::class,
        'app:ai-agent:validate-conversation' => AgentValidateConversationCommand::class,
        'app:ai-agent:skill-token-map' => SkillTokenMapCommand::class,
    ];

    /** @var string The tool-call child: run by name, never listed */
    public const string HIDDEN_COMMAND_NAME = 'app:ai-agent:execute-tool-call';

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
        self::assertTrue($application->find('app:ai-agent:execute-tool-call')->isHidden());
        self::assertArrayNotHasKey('app:ai-agent:execute-tool-call', array_filter($application->all(), static fn ($command): bool => !$command->isHidden()));
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
        self::assertCount(19, $visibleCommandNames);
        self::assertSame([], array_values(array_diff($visibleCommandNames, $listedCommandNames)), 'every visible command is listed');
        self::assertNotContains(self::HIDDEN_COMMAND_NAME, $listedCommandNames);
        self::assertContains(self::HIDDEN_COMMAND_NAME, $hiddenCommandNames);
        $textListProcess = $this->runConsoleScript('list', '--raw');
        self::assertSame(19, preg_match_all('/^app:ai-(agent|conversation):\S+/m', $textListProcess->getOutput()), 'the text listing names the 19 visible commands');

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
        $commandTester = new CommandTester($application->find('app:ai-agent:context-eviction-sim'));

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
