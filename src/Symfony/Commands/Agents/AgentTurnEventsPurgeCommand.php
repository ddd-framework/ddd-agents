<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The retention purge of the agent turn events: deletes the events older than the retention (default
 * {@see AIConversationTurnEventsService::RETENTION_DAYS} days) in batches, then the catalog snapshots no event
 * references any more. This is the table's own age-based lifecycle, independent of any erasure of an account. Run it
 * daily from a cron:
 *
 *   php bin/console app:ai-agent:turn-events-purge
 *   php bin/console app:ai-agent:turn-events-purge --days=90 --dryRun
 */
#[AsCommand(
    name: 'app:ai-agent:turn-events-purge',
    description: 'Delete agent turn events older than the retention (default 180 days) in batches, then the unreferenced catalog snapshots'
)]
class AgentTurnEventsPurgeCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention in days', (string)AIConversationTurnEventsService::RETENTION_DAYS)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per DELETE batch', '5000')
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Print the cutoff and exit without deleting');
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * Deletes the turn events past the retention and the snapshots nothing references.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(1800);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $retentionDays = max(1, (int)$input->getOption('days'));
        $batchSize = max(1, (int)$input->getOption('batch'));
        if ((bool)$input->getOption('dryRun')) {
            $symfonyStyle->note("dry-run: would delete turn events older than {$retentionDays} days in batches of {$batchSize}, then the unreferenced catalog snapshots");
            return Command::SUCCESS;
        }
        try {
            /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
            $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
            $deletedEventCount = $aIConversationTurnEventsService->purgeOlderThanDays($retentionDays, $batchSize);
            $deletedSnapshotCount = $aIConversationTurnEventsService->purgeUnreferencedSnapshots($batchSize);
            $symfonyStyle->success("deleted {$deletedEventCount} turn events older than {$retentionDays} days and {$deletedSnapshotCount} unreferenced catalog snapshots");
            return Command::SUCCESS;
        } catch (Throwable $purgeError) {
            $symfonyStyle->error($purgeError->getMessage());
            return Command::FAILURE;
        }
    }
}
