<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The retention purge of the conversation trace events: deletes the events older than the retention (default
 * {@see AIConversationTraceEventsService::RETENTION_DAYS} days) in batches. Erasing a conversation removes its trace
 * rows through the foreign-key cascade; this is the table's own age-based lifecycle, which also catches call events
 * never linked to a conversation. Run it daily from a cron:
 *
 *   php bin/console app:ai-agent:trace-events-purge
 *   php bin/console app:ai-agent:trace-events-purge --days=90 --batch=2000
 */
#[AsCommand(
    name: 'app:ai-agent:trace-events-purge',
    description: 'Delete conversation trace events older than the retention (default 180 days) in batches'
)]
class AgentTraceEventsPurgeCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention in days', (string)AIConversationTraceEventsService::RETENTION_DAYS)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per DELETE batch', '5000')
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Print the cutoff and exit without deleting');
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * Deletes the trace events past the retention.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(1800);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $retentionDays = max(1, (int)$input->getOption('days'));
        $batchSize = max(1, (int)$input->getOption('batch'));
        if ((bool)$input->getOption('dryRun')) {
            $symfonyStyle->note("dry-run: would delete trace events older than {$retentionDays} days in batches of {$batchSize}");
            return Command::SUCCESS;
        }
        try {
            /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
            $aIConversationTraceEventsService = DDDService::instance()->getService(AIConversationTraceEventsService::class);
            $deletedEventCount = $aIConversationTraceEventsService->purgeOlderThanDays($retentionDays, $batchSize);
            $symfonyStyle->success("deleted {$deletedEventCount} trace events older than {$retentionDays} days");
            return Command::SUCCESS;
        } catch (Throwable $purgeError) {
            $symfonyStyle->error($purgeError->getMessage());
            return Command::FAILURE;
        }
    }
}
