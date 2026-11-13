<?php
// placeholder {{NAMESPACE}} sample: App\Symfony\Commands\Agents
// placeholder {{COMMAND_NAME}} sample: agent:schedules:sweep-state

declare(strict_types=1);

namespace {{NAMESPACE}};

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
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
 * Host duty: sweep the state of the agent's schedules (hourly: a schedule the platform paused fires again only after this sweep re-armed it).
 *
 * Per candidate (a schedule the platform paused, or one the host's filter names): the eligibility of its space, or of
 * its account for a schedule without a space, is read fresh; the schedule decides its transition. An ended or erased
 * scope closes the schedule, cancels its in-flight runs and closes their owed reports; a suspended scope pauses it; a
 * platform pause whose reason cleared is re-armed. An owner's own pause is never touched.
 *
 * The candidate scan reads only the package's tables: a host whose spaces or accounts can end overrides
 * `buildAutomationStateSweepSqlFilter()` in its same-path subclass of the schedules service, otherwise only the
 * platform-paused schedules are candidates.
 *
 * Account: none. The rights are lifted for the whole pass. Usage envelope: none, no model call.
 */
#[AsCommand(name: '{{COMMAND_NAME}}', description: 'Close, pause or re-arm agent schedules whose space or account changed its automation state.')]
class AgentSchedulesStateSweepCommand extends Command
{
    use LockableTrait;

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Candidates per pass', (string)AIConversationSchedulesService::STATE_SWEEP_BATCH_LIMIT);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the decisions, apply none');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('A previous pass is still running.');
            return Command::SUCCESS;
        }
        $batchLimit = (int)$input->getOption('batch') ?: AIConversationSchedulesService::STATE_SWEEP_BATCH_LIMIT;
        $dryRun = (bool)$input->getOption('dry-run');
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        $previousAccount = AuthService::instance()->getAccount();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $transitionedCount = 0;
        try {
            foreach ($aIConversationSchedulesService->findScheduleIdsForAutomationStateSweep($batchLimit) as $candidateScheduleId) {
                try {
                    $schedule = $aIConversationSchedulesService->find($candidateScheduleId, useEntityRegistrCache: false);
                    if (!$schedule instanceof AIConversationSchedule) {
                        continue;
                    }
                    $transition = $aIConversationSchedulesService->applyAutomationStateSweepToSchedule($schedule, $dryRun);
                    if ($transition === null) {
                        continue;
                    }
                    $transitionedCount++;
                    $output->writeln("Schedule {$candidateScheduleId}: {$transition->targetStatus} (" . ($transition->stateReason ?? 'reason cleared') . ')');
                } catch (Throwable $sweepError) {
                    $output->writeln("<error>Schedule {$candidateScheduleId}: {$sweepError->getMessage()}</error>");
                }
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            AuthService::instance()->setAccount($previousAccount);
            $this->release();
        }
        $output->writeln(($dryRun ? 'Would transition ' : 'Transitioned ') . $transitionedCount . ' schedule(s).');
        return Command::SUCCESS;
    }
}
