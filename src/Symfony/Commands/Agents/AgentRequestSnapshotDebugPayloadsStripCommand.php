<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Removes the debug payloads (`sentPayloadJson`, `rawResponseJson`) from the request snapshots of messages older than
 * N days: the full outgoing request and raw response that a conversation with `storeRequestSnapshot` on writes on
 * every model call. The slim snapshot stays. Primary-key windows, small autocommit writes, adaptive pauses, a wait
 * while the server is busy, a time budget and a resumable cursor — see
 * {@see AIConversationMessagesService::removeDebugPayloadsFromRequestSnapshots()}.
 *
 * As a nightly cron, pass a time budget below the cron ticker's process timeout; the cursor carries the rest to the
 * next night:
 *
 *   php bin/console app:ai-agent:request-snapshot-debug-payloads-strip --maxSeconds=300
 *   php bin/console app:ai-agent:request-snapshot-debug-payloads-strip --olderThanDays=7 --dryRun
 */
#[AsCommand(
    name: 'app:ai-agent:request-snapshot-debug-payloads-strip',
    description: 'Remove the debug request/response payloads from request snapshots older than N days, in small pauses'
)]
class AgentRequestSnapshotDebugPayloadsStripCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('olderThanDays', null, InputOption::VALUE_REQUIRED, 'Only snapshots of messages older than this many days', '2')
            ->addOption('windowSize', null, InputOption::VALUE_REQUIRED, 'Message ids scanned per window (primary-key range)', '1000')
            ->addOption('chunkSize', null, InputOption::VALUE_REQUIRED, 'Rows per UPDATE', '50')
            ->addOption('pauseMs', null, InputOption::VALUE_REQUIRED, 'Base pause after every write and window (doubles while writes are slow)', '200')
            ->addOption('slowWriteMs', null, InputOption::VALUE_REQUIRED, 'A chunk write slower than this counts as server pressure and doubles the pause', '2000')
            ->addOption('maxSeconds', null, InputOption::VALUE_REQUIRED, 'Time budget of this run; the next run continues from the cursor', '1500')
            ->addOption('maxThreadsRunning', null, InputOption::VALUE_REQUIRED, 'Wait while more statements than this execute on the server', '40')
            ->addOption('maxHistoryListLength', null, InputOption::VALUE_REQUIRED, 'Wait while the purge backlog of the database is longer than this', '200000')
            ->addOption('fromMessageId', null, InputOption::VALUE_REQUIRED, 'Start at this message id instead of the stored cursor')
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Scan and count only — no write, cursor not moved');
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * Runs the removal and prints its report.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(3600);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $fromMessageIdOption = $input->getOption('fromMessageId');
        $dryRun = (bool)$input->getOption('dryRun');

        try {
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $removalReport = $aIConversationMessagesService->removeDebugPayloadsFromRequestSnapshots(
                olderThanDays: max(1, (int)$input->getOption('olderThanDays')),
                windowSize: max(1, (int)$input->getOption('windowSize')),
                chunkSize: max(1, (int)$input->getOption('chunkSize')),
                pauseMs: max(0, (int)$input->getOption('pauseMs')),
                slowWriteMs: max(1, (int)$input->getOption('slowWriteMs')),
                maxSeconds: max(1, (int)$input->getOption('maxSeconds')),
                maxThreadsRunning: max(1, (int)$input->getOption('maxThreadsRunning')),
                maxHistoryListLength: max(1, (int)$input->getOption('maxHistoryListLength')),
                dryRun: $dryRun,
                fromMessageId: $fromMessageIdOption !== null ? (int)$fromMessageIdOption : null,
                progressReporter: $this->getProgressReporter()
            );
            $reportTable = new Table($output);
            $reportTable->setHeaders(['', 'value']);
            foreach ($removalReport->toReportFields() as $reportFieldName => $reportFieldValue) {
                $reportTable->addRow([$reportFieldName, $reportFieldValue === null ? '-' : (string)$reportFieldValue]);
            }
            $reportTable->render();
            $symfonyStyle->success(($dryRun ? 'dry run: ' : '') . "{$removalReport->rowsStripped} snapshots stripped, stopped because: {$removalReport->stoppedBecause}");
            return Command::SUCCESS;
        } catch (Throwable $removalError) {
            $symfonyStyle->error($removalError->getMessage());
            return Command::FAILURE;
        }
    }
}
