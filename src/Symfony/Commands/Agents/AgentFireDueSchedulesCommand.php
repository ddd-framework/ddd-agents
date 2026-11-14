<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleOccurrenceSpawn;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleTransition;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The schedule fire cron (every five minutes, beside the recovery passes). Per due {@see AIConversationSchedule}
 * (`status = ACTIVE AND nextRunAt <= now`, oldest first, bounded):
 *
 * 1. EXPIRY — past {@see AIConversationSchedule::$ttlAt} or {@see AIConversationSchedule::$maxIterations} → the
 *    schedule becomes EXPIRED and does not fire.
 * 2. CLAIM — {@see AIConversationSchedulesService::claimFireOccurrence()}: one compare-and-set UPDATE advances
 *    `nextRunAt` (failure backoff included) and stamps `lastFiredAt`; exactly one process wins, no double fire.
 * 3. SPAWN — {@see AIConversationSchedulesService::spawnRunConversationForClaimedSchedule()} starts one run
 *    conversation, or skips the occurrence: the previous run is still in progress, the schedule has no agent space,
 *    the agent space or its account is not eligible for automated work, or the schedule budget pot is exhausted.
 *
 * Why an occurrence was skipped is asked from the schedules service the container returns
 * ({@see AIConversationSchedulesService::isSkipReasonOfMissingAgentSpace()} and its siblings): an application's
 * service may answer with values of its own, so the command compares against no constant.
 *
 *   php bin/console app:ai-conversation:fire-due-schedules
 *   php bin/console app:ai-conversation:fire-due-schedules --batch=20 --dry-run
 */
#[AsCommand(
    name: 'app:ai-conversation:fire-due-schedules',
    description: 'Fire every due AI conversation schedule: claim its occurrence and spawn the run conversation.',
)]
class AgentFireDueSchedulesCommand extends AgentConsoleCommand
{
    use LockableTrait;

    /** @var array<string, int> The counts of one pass, by outcome */
    protected array $outcomeCounts = [];

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Per-pass candidate cap', (string)AIConversationSchedulesService::DUE_SCHEDULES_BATCH_LIMIT);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the due schedules without claiming or firing');
        // the spawn stores budgetBypass on every run conversation it starts while the process grant is set, so the
        // workers that drive the runs inherit it; a cron never passes it
        $this->configureIgnoreBudgetOption();
    }

    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * One pass over the due schedules; a second pass that starts while one runs does nothing.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        // the claim already prevents a double fire; one pass at a time also spares two passes loading the same batch
        if (!$this->lock()) {
            $output->writeln('Schedule firing: a previous run is still active — skipping this tick.');
            return Command::SUCCESS;
        }
        $batchLimit = (int)$input->getOption('batch') ?: AIConversationSchedulesService::DUE_SCHEDULES_BATCH_LIMIT;
        $dryRun = (bool)$input->getOption('dry-run');
        $aIConversationSchedulesService = $this->getAIConversationSchedulesService();
        $this->outcomeCounts = [
            'fired' => 0,
            'skippedPreviousRunInProgress' => 0,
            'skippedBudgetPotExhausted' => 0,
            'skippedNotEligible' => 0,
            'skippedOtherReason' => 0,
            'closedAgentSpaceMissing' => 0,
            'expired' => 0,
            'claimLost' => 0,
            'failed' => 0,
        ];
        try {
            foreach ($aIConversationSchedulesService->findDueScheduleIds($batchLimit) as $dueScheduleId) {
                try {
                    $this->fireDueSchedule((int)$dueScheduleId, $dryRun, $aIConversationSchedulesService, $output);
                } catch (Throwable $fireError) {
                    $this->outcomeCounts['failed']++;
                    $output->writeln("<error>Failed: schedule {$dueScheduleId}: {$fireError->getMessage()}</error>");
                }
            }
        } finally {
            $this->release();
        }

        $output->writeln(sprintf(
            'Schedule firing: %d fired, %d skipped (previous run in progress), %d skipped (budget pot exhausted), %d skipped (agent space/account not eligible), %d skipped (other reason), %d closed (no agent space), %d expired, %d claim-lost, %d failed.',
            $this->outcomeCounts['fired'],
            $this->outcomeCounts['skippedPreviousRunInProgress'],
            $this->outcomeCounts['skippedBudgetPotExhausted'],
            $this->outcomeCounts['skippedNotEligible'],
            $this->outcomeCounts['skippedOtherReason'],
            $this->outcomeCounts['closedAgentSpaceMissing'],
            $this->outcomeCounts['expired'],
            $this->outcomeCounts['claimLost'],
            $this->outcomeCounts['failed']
        ));
        return Command::SUCCESS;
    }

    /**
     * Expires, claims and spawns one due schedule, and counts the outcome.
     *
     * @param int $dueScheduleId
     * @param bool $dryRun
     * @param AIConversationSchedulesService $aIConversationSchedulesService
     * @param OutputInterface $output
     * @return void
     */
    protected function fireDueSchedule(int $dueScheduleId, bool $dryRun, AIConversationSchedulesService $aIConversationSchedulesService, OutputInterface $output): void
    {
        $schedule = $aIConversationSchedulesService->find($dueScheduleId, useEntityRegistrCache: false);
        if (!$schedule instanceof AIConversationSchedule || !$schedule->isActive()) {
            return; // paused or cancelled between the scan and the load
        }
        $expiryTransition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_EXPIRE, new DateTime());
        if ($expiryTransition->isAllowed) {
            if (!$dryRun) {
                $aIConversationSchedulesService->transitionStatus($schedule, AIConversationScheduleStatus::EXPIRED);
            }
            $this->outcomeCounts['expired']++;
            $output->writeln("Expired: schedule {$dueScheduleId} passed its ttl/maxIterations — transitioned to EXPIRED");
            return;
        }
        if ($dryRun) {
            $output->writeln("Due (dry-run): schedule {$dueScheduleId} (account {$schedule->accountId}) would fire");
            return;
        }
        if (!$aIConversationSchedulesService->claimFireOccurrence($schedule)) {
            $this->outcomeCounts['claimLost']++;
            return; // another process, or a concurrent pause or cancel, won the occurrence
        }
        $occurrenceSpawn = $aIConversationSchedulesService->spawnRunConversationForClaimedSchedule($schedule);
        $runConversation = $occurrenceSpawn->runConversation;
        if ($runConversation === null) {
            $this->reportSkippedOccurrence($dueScheduleId, $occurrenceSpawn, $aIConversationSchedulesService, $output);
            return;
        }
        $this->outcomeCounts['fired']++;
        $output->writeln("Fired: schedule {$dueScheduleId} spawned run conversation {$runConversation->id} (iteration {$schedule->iterationsUsed})");
    }

    /**
     * Counts and prints why an occurrence started no run, asking the schedules service which skip it was.
     *
     * @param int $dueScheduleId
     * @param AIConversationScheduleOccurrenceSpawn $occurrenceSpawn
     * @param AIConversationSchedulesService $aIConversationSchedulesService
     * @param OutputInterface $output
     * @return void
     */
    protected function reportSkippedOccurrence(int $dueScheduleId, AIConversationScheduleOccurrenceSpawn $occurrenceSpawn, AIConversationSchedulesService $aIConversationSchedulesService, OutputInterface $output): void
    {
        $skipReason = (string)$occurrenceSpawn->skipReason;
        if ($aIConversationSchedulesService->isSkipReasonOfMissingAgentSpace($skipReason)) {
            $this->outcomeCounts['closedAgentSpaceMissing']++;
            $output->writeln("Closed: schedule {$dueScheduleId} has no agent space — cancelled, no failure counted");
            return;
        }
        if ($aIConversationSchedulesService->isSkipReasonOfIneligibleAgentSpace($skipReason)) {
            $this->outcomeCounts['skippedNotEligible']++;
            $skipEligibility = $occurrenceSpawn->skipEligibility;
            $output->writeln("Skipped: schedule {$dueScheduleId} — the agent space/account is {$skipEligibility?->state} ({$skipEligibility?->reason}); no run, no failure streak");
            return;
        }
        if ($aIConversationSchedulesService->isSkipReasonOfExhaustedBudgetPot($skipReason)) {
            $this->outcomeCounts['skippedBudgetPotExhausted']++;
            $output->writeln("Skipped: schedule {$dueScheduleId} — the schedule budget pot is exhausted (runs pause until the 1st)");
            return;
        }
        if ($aIConversationSchedulesService->isSkipReasonOfPreviousRunInProgress($skipReason)) {
            $this->outcomeCounts['skippedPreviousRunInProgress']++;
            $output->writeln("Skipped: schedule {$dueScheduleId} — the previous run conversation is still in progress");
            return;
        }
        // a reason an application's service added: counted apart, printed as the service named it
        $this->outcomeCounts['skippedOtherReason']++;
        $output->writeln("Skipped: schedule {$dueScheduleId} — skip reason '{$skipReason}'");
    }

    /** @return AIConversationSchedulesService the schedules service the container returns (the application's class, when it replaces it) */
    protected function getAIConversationSchedulesService(): AIConversationSchedulesService
    {
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        return $aIConversationSchedulesService;
    }
}
