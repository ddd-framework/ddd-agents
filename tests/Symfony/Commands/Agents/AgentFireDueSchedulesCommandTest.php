<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleOccurrenceSpawn;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentFireDueSchedulesCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The fire command asks the schedules service it got which skip an occurrence was: an application whose service
 * redeclares the skip reasons of the agent space with its own values is counted right, a reason the service does not
 * name is counted apart, and the command names no skip constant.
 */
class AgentFireDueSchedulesCommandTest extends UnitTestCase
{
    public function testTheCommandKeepsItsNameAndRunsOnTheBase(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentFireDueSchedulesCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:ai-conversation:fire-due-schedules', $asCommandAttributes[0]->newInstance()->name);

        $agentFireDueSchedulesCommand = new AgentFireDueSchedulesCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $agentFireDueSchedulesCommand);
        self::assertTrue($agentFireDueSchedulesCommand->getDefinition()->hasOption('dry-run'));
        self::assertTrue($agentFireDueSchedulesCommand->getDefinition()->hasOption(AgentConsoleCommand::OPTION_IGNORE_BUDGET));
    }

    public function testEverySkipOfAnApplicationsOwnWordsIsCountedUnderItsOutcome(): void
    {
        $schedulesServiceDouble = new SchedulesServiceWithOwnSkipWordsDouble();
        $this->scriptOccurrence($schedulesServiceDouble, 1, AIConversationScheduleOccurrenceSpawn::skipped(SchedulesServiceWithOwnSkipWordsDouble::OCCURRENCE_SKIP_REASON_AGENT_SPACE_MISSING));
        $notEligible = new AgentAutomationEligibility();
        $notEligible->state = AgentAutomationEligibility::STATE_SUSPENDED;
        $notEligible->reason = 'LOCKED';
        $this->scriptOccurrence($schedulesServiceDouble, 2, AIConversationScheduleOccurrenceSpawn::skipped(SchedulesServiceWithOwnSkipWordsDouble::OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE, $notEligible));
        $this->scriptOccurrence($schedulesServiceDouble, 3, AIConversationScheduleOccurrenceSpawn::skipped(SchedulesServiceWithOwnSkipWordsDouble::OCCURRENCE_SKIP_REASON_BUDGET_POT_EXHAUSTED));
        $this->scriptOccurrence($schedulesServiceDouble, 4, AIConversationScheduleOccurrenceSpawn::skipped(SchedulesServiceWithOwnSkipWordsDouble::OCCURRENCE_SKIP_REASON_PREVIOUS_RUN_IN_PROGRESS));
        $runConversation = new AIConversation();
        $runConversation->id = 99;
        $this->scriptOccurrence($schedulesServiceDouble, 5, AIConversationScheduleOccurrenceSpawn::spawned($runConversation));
        $this->scriptOccurrence($schedulesServiceDouble, 6, AIConversationScheduleOccurrenceSpawn::skipped('SHOP_ON_HOLIDAY'));

        $commandTester = new CommandTester(new AgentFireDueSchedulesProbeCommand($schedulesServiceDouble));
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Closed: schedule 1 has no agent space', $display);
        self::assertStringContainsString('Skipped: schedule 2 — the agent space/account is SUSPENDED (LOCKED)', $display);
        self::assertStringContainsString('Skipped: schedule 3 — the schedule budget pot is exhausted', $display);
        self::assertStringContainsString('Skipped: schedule 4 — the previous run conversation is still in progress', $display);
        self::assertStringContainsString('Fired: schedule 5 spawned run conversation 99', $display);
        self::assertStringContainsString("Skipped: schedule 6 — skip reason 'SHOP_ON_HOLIDAY'", $display);
        self::assertStringContainsString(
            'Schedule firing: 1 fired, 1 skipped (previous run in progress), 1 skipped (budget pot exhausted), 1 skipped (agent space/account not eligible), 1 skipped (other reason), 1 closed (no agent space), 0 expired, 0 claim-lost, 0 failed.',
            $display
        );
        self::assertSame([1, 2, 3, 4, 5, 6], $schedulesServiceDouble->claimedScheduleIds);
        self::assertSame([1, 2, 3, 4, 5, 6], $schedulesServiceDouble->spawnedScheduleIds);
    }

    public function testTheDryRunClaimsAndSpawnsNothing(): void
    {
        $schedulesServiceDouble = new SchedulesServiceWithOwnSkipWordsDouble();
        $this->scriptOccurrence($schedulesServiceDouble, 1, AIConversationScheduleOccurrenceSpawn::skipped(SchedulesServiceWithOwnSkipWordsDouble::OCCURRENCE_SKIP_REASON_AGENT_SPACE_MISSING));

        $commandTester = new CommandTester(new AgentFireDueSchedulesProbeCommand($schedulesServiceDouble));
        $commandTester->execute(['--dry-run' => true]);

        self::assertStringContainsString('Due (dry-run): schedule 1 (account 5) would fire', $commandTester->getDisplay());
        self::assertSame([], $schedulesServiceDouble->recordedStatusTransitions);
        self::assertSame([], $schedulesServiceDouble->claimedScheduleIds, 'a dry run claims no occurrence');
        self::assertSame([], $schedulesServiceDouble->spawnedScheduleIds, 'a dry run spawns no run');
    }

    public function testTheCommandNamesNoSkipConstant(): void
    {
        $commandSource = (string)file_get_contents((string)(new ReflectionClass(AgentFireDueSchedulesCommand::class))->getFileName());

        self::assertStringNotContainsString('OCCURRENCE_SKIP_REASON_', $commandSource);
    }

    protected function scriptOccurrence(SchedulesServiceWithOwnSkipWordsDouble $schedulesServiceDouble, int $scheduleId, AIConversationScheduleOccurrenceSpawn $occurrenceSpawn): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->id = $scheduleId;
        $schedule->accountId = 5;
        $schedule->status = AIConversationScheduleStatus::ACTIVE;
        $schedulesServiceDouble->dueSchedulesById[$scheduleId] = $schedule;
        $schedulesServiceDouble->occurrenceSpawnsByScheduleId[$scheduleId] = $occurrenceSpawn;
    }
}
