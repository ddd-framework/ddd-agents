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
 * Rewrites the loaded-capability rows that still carry their complete set into the chain format: one checkpoint per
 * turn, every other row only its changes. One conversation at a time, active conversations skipped, short
 * lock-checked transactions per block, an adaptive pause, a wait while the server is busy, a time budget and a
 * resumable cursor — see {@see AIConversationMessagesService::compactLoadedCapabilities()}.
 *
 * Run it only once every process that reads these rows can read the chain format: a reader without it takes a change
 * row for a row without a set. As a nightly cron:
 *
 *   php bin/console app:ai-agent:loaded-capabilities-compact
 *   php bin/console app:ai-agent:loaded-capabilities-compact --conversationId=123 --dryRun --verify
 */
#[AsCommand(
    name: 'app:ai-agent:loaded-capabilities-compact',
    description: 'Rewrite legacy loaded-capability rows into turn checkpoints plus per-row changes, in small pauses'
)]
class AgentLoadedCapabilitiesCompactCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('conversationId', null, InputOption::VALUE_REQUIRED, 'Only this conversation (tests)')
            ->addOption('fromConversationId', null, InputOption::VALUE_REQUIRED, 'Start after this conversation id instead of the stored cursor')
            ->addOption('batchSize', null, InputOption::VALUE_REQUIRED, 'Conversations read per batch', '200')
            ->addOption('pauseMs', null, InputOption::VALUE_REQUIRED, 'Base pause after every compacted conversation (doubles while writes are slow)', '200')
            ->addOption('slowWriteMs', null, InputOption::VALUE_REQUIRED, 'A block write slower than this counts as server pressure and doubles the pause', '2000')
            ->addOption('maxSeconds', null, InputOption::VALUE_REQUIRED, 'Time budget of this run; the next run continues from the cursor', '1500')
            ->addOption('maxThreadsRunning', null, InputOption::VALUE_REQUIRED, 'Wait while more statements than this execute on the server', '40')
            ->addOption('quietMinutes', null, InputOption::VALUE_REQUIRED, 'Skip conversations with a message younger than this', '30')
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Compute and report only — no write, cursor not moved')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'After rewriting a conversation, reload it through the hydrating loader and compare every row with its former complete set');
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * Runs the rewrite and prints its report.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(3600);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dryRun');
        $conversationIdOption = $input->getOption('conversationId');
        $fromConversationIdOption = $input->getOption('fromConversationId');

        try {
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $rewriteReport = $aIConversationMessagesService->compactLoadedCapabilities(
                batchSize: max(1, (int)$input->getOption('batchSize')),
                pauseMs: max(0, (int)$input->getOption('pauseMs')),
                slowWriteMs: max(1, (int)$input->getOption('slowWriteMs')),
                maxSeconds: max(1, (int)$input->getOption('maxSeconds')),
                maxThreadsRunning: max(1, (int)$input->getOption('maxThreadsRunning')),
                quietMinutes: max(0, (int)$input->getOption('quietMinutes')),
                dryRun: $dryRun,
                fromConversationId: $fromConversationIdOption !== null ? (int)$fromConversationIdOption : null,
                onlyConversationId: $conversationIdOption !== null ? (int)$conversationIdOption : null,
                progressReporter: $this->getProgressReporter(),
                verify: (bool)$input->getOption('verify')
            );
            $reportTable = new Table($output);
            $reportTable->setHeaders(['', 'value']);
            foreach ($rewriteReport->toReportFields() as $reportFieldName => $reportFieldValue) {
                $reportTable->addRow([$reportFieldName, $reportFieldValue === null ? '-' : (string)$reportFieldValue]);
            }
            $reportTable->render();
            $symfonyStyle->success(($dryRun ? 'dry run: ' : '') . "{$rewriteReport->rowsRewritten} rows rewritten in {$rewriteReport->conversationsCompacted} conversations, stopped because: {$rewriteReport->stoppedBecause}");
            return Command::SUCCESS;
        } catch (Throwable $rewriteError) {
            $symfonyStyle->error($rewriteError->getMessage());
            return Command::FAILURE;
        }
    }
}
