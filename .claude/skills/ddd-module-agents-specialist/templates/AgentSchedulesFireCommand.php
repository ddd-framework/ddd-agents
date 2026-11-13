<?php
// placeholder {{NAMESPACE}} sample: App\Symfony\Commands\Agents
// placeholder {{COMMAND_NAME}} sample: agent:schedules:fire-due

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleTransition;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Host duty: fire the due schedules of the agent (every minute to every five minutes).
 *
 * Per due schedule (ACTIVE, fire anchor passed, oldest first, bounded): expire it when its time-to-live or its
 * iteration cap is used up; otherwise claim the occurrence (one compare-and-set, so two runners never fire it twice)
 * and spawn the run conversation. The spawn itself applies the guardrails: the previous run still in progress, a
 * space that no longer exists, a space or account not eligible for automated work, an exhausted schedule budget pot.
 *
 * Account: none of its own. The rights are lifted for the scan and the spawn; the run is spawned as the schedule's
 * account. Usage envelope: none here. The spawn dispatches the turn, and the turn worker opens its envelope.
 */
#[AsCommand(name: '{{COMMAND_NAME}}', description: 'Fire the due agent schedules: claim each occurrence once and spawn its run conversation.')]
class AgentSchedulesFireCommand extends Command
{
    use LockableTrait;

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Due schedules per pass', (string)AIConversationSchedulesService::DUE_SCHEDULES_BATCH_LIMIT);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the due schedules, claim and fire nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('A previous pass is still running.');
            return Command::SUCCESS;
        }
        $batchLimit = (int)$input->getOption('batch') ?: AIConversationSchedulesService::DUE_SCHEDULES_BATCH_LIMIT;
        $dryRun = (bool)$input->getOption('dry-run');
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        $previousAccount = AuthService::instance()->getAccount();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $firedCount = 0;
        $skippedCount = 0;
        try {
            foreach ($aIConversationSchedulesService->findDueScheduleIds($batchLimit) as $dueScheduleId) {
                try {
                    $schedule = $aIConversationSchedulesService->find($dueScheduleId, useEntityRegistrCache: false);
                    if (!$schedule instanceof AIConversationSchedule || !$schedule->isActive()) {
                        continue;
                    }
                    if ($schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_EXPIRE, new DateTime())->isAllowed) {
                        if (!$dryRun) {
                            $aIConversationSchedulesService->transitionStatus($schedule, AIConversationScheduleStatus::EXPIRED);
                        }
                        $output->writeln("Schedule {$dueScheduleId}: expired");
                        continue;
                    }
                    if ($dryRun) {
                        $output->writeln("Schedule {$dueScheduleId}: due");
                        continue;
                    }
                    if (!$aIConversationSchedulesService->claimFireOccurrence($schedule)) {
                        continue;
                    }
                    $occurrenceSpawn = $aIConversationSchedulesService->spawnRunConversationForClaimedSchedule($schedule);
                    if ($occurrenceSpawn->runConversation === null) {
                        $skippedCount++;
                        $output->writeln("Schedule {$dueScheduleId}: skipped ({$occurrenceSpawn->skipReason})");
                        continue;
                    }
                    $firedCount++;
                    $output->writeln("Schedule {$dueScheduleId}: run conversation {$occurrenceSpawn->runConversation->id}");
                } catch (Throwable $fireError) {
                    $output->writeln("<error>Schedule {$dueScheduleId}: {$fireError->getMessage()}</error>");
                }
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            AuthService::instance()->setAccount($previousAccount);
            $this->release();
        }
        $output->writeln("Fired {$firedCount}, skipped {$skippedCount}.");
        return Command::SUCCESS;
    }
}
