<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationEmbeddingsRepairReport;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationEmbeddingsRepairSettings;
use DDD\Domain\AI\Services\Conversations\AIConversationEmbeddingsRepairService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Repairs the recall embeddings that hold the zero vector — message embedding rows, compaction-node summary embeddings
 * and memory embeddings — in small pauses and resumable; see {@see AIConversationEmbeddingsRepairService}. Dry run by
 * default (counts and classifies), `--apply` writes:
 *
 *   php bin/console app:ai-conversation:repair-zero-embeddings                     # dry run, every table
 *   php bin/console app:ai-conversation:repair-zero-embeddings --apply --limit=50  # a first small writing round
 *   php bin/console app:ai-conversation:repair-zero-embeddings --apply --target=nodes --batchSize=10 --sleepMs=2000
 *
 * A run that stops early (limit, time budget, failure) prints the options it resumes with (`--afterMessageEmbeddingId`,
 * `--afterCompactionNodeId`, `--afterMemoryId`); starting from scratch works too, a repaired row no longer holds the
 * zero vector. As a cron, scan only the newest rows and cap the budget so a run ends inside the cron ticker's process
 * timeout, e.g. `--apply --newestRowsOnly=50000 --limit=200 --maxSeconds=200`.
 */
#[AsCommand(
    name: 'app:ai-conversation:repair-zero-embeddings',
    description: 'Re-embed (or delete) the recall embeddings that hold the zero vector (messages, compaction nodes, memories) — dry run unless --apply'
)]
class AIConversationEmbeddingsRepairCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $settingsDefaults = new AIConversationEmbeddingsRepairSettings();
        $this
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Which embeddings: ' . implode(' | ', AIConversationEmbeddingsRepairSettings::TARGETS), $settingsDefaults->target)
            ->addOption('batchSize', null, InputOption::VALUE_REQUIRED, 'Rows re-embedded between two pauses', (string)$settingsDefaults->batchSize)
            ->addOption('sleepMs', null, InputOption::VALUE_REQUIRED, 'Pause after every batch and every scan query (milliseconds)', (string)$settingsDefaults->sleepMs)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many repaired rows — failures do not count; found rows in a dry run (0 = all)', (string)$settingsDefaults->limit)
            ->addOption('scanWindow', null, InputOption::VALUE_REQUIRED, 'Id values one scan query covers', (string)$settingsDefaults->scanWindow)
            ->addOption('maxThreadsRunning', null, InputOption::VALUE_REQUIRED, 'Writes wait while more statements than this execute on the server', (string)$settingsDefaults->maxThreadsRunning)
            ->addOption('maxHistoryListLength', null, InputOption::VALUE_REQUIRED, 'Writes wait while the purge backlog of the database is longer than this', (string)$settingsDefaults->maxHistoryListLength)
            ->addOption('afterMessageEmbeddingId', null, InputOption::VALUE_REQUIRED, 'Start the message-embedding scan after this row id', '0')
            ->addOption('afterCompactionNodeId', null, InputOption::VALUE_REQUIRED, 'Start the compaction-node scan after this node id', '0')
            ->addOption('afterMemoryId', null, InputOption::VALUE_REQUIRED, 'Start the memory scan after this memory id', '0')
            ->addOption('newestRowsOnly', null, InputOption::VALUE_REQUIRED, 'Scan only the newest this-many ids of each table (0 = the whole table)', (string)$settingsDefaults->newestRowsOnly)
            ->addOption('maxSeconds', null, InputOption::VALUE_REQUIRED, 'Time budget of the run in seconds (0 = none; a positive budget is capped at ' . AIConversationEmbeddingsRepairSettings::MAXIMUM_SECONDS . ' s so a cron run ends inside the ticker\'s process timeout)', (string)$settingsDefaults->maxSeconds)
            ->addOption('dryRun', null, InputOption::VALUE_NONE, 'Only count (the default; wins over --apply)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write — without it the run only counts');
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /** A repair over every account: the message loads and the writer's row reads must see every row. */
    protected function liftsEntityRightsRestrictions(): bool
    {
        return true;
    }

    /**
     * Runs the repair and prints its figures.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        $symfonyStyle = new SymfonyStyle($input, $output);

        try {
            $repairSettings = $this->buildSettingsFromInput($input);
            /** @var AIConversationEmbeddingsRepairService $aIConversationEmbeddingsRepairService */
            $aIConversationEmbeddingsRepairService = DDDService::instance()->getService(AIConversationEmbeddingsRepairService::class);
            $symfonyStyle->writeln(($repairSettings->dryRun ? '[dry run] ' : '') . "target: {$repairSettings->target}, batch {$repairSettings->batchSize}, pause {$repairSettings->sleepMs} ms, limit " . ($repairSettings->limit > 0 ? $repairSettings->limit : 'none'));
            $repairReport = $aIConversationEmbeddingsRepairService->repairZeroEmbeddings($repairSettings, $this->getProgressReporter());

            $summaryTable = new Table($output);
            $summaryTable->setHeaders(['', $repairSettings->dryRun ? 'value (would)' : 'value']);
            foreach ($repairReport->buildFigures() as $figureName => $figureValue) {
                $summaryTable->addRow([$figureName, (string)$figureValue]);
            }
            $summaryTable->render();
            $summaryLine = ($repairSettings->dryRun ? 'dry run: ' : '')
                . "{$repairReport->zeroMessageEmbeddingRowsFound} zero message rows, {$repairReport->compactionNodes->zeroRowsFound} zero nodes,"
                . " {$repairReport->memories->zeroRowsFound} zero memories, stopped because: {$repairReport->stoppedBecause}";
            if ($repairReport->stoppedBecause !== AIConversationEmbeddingsRepairReport::STOPPED_SCAN_COMPLETE) {
                $symfonyStyle->writeln('resume with: ' . $repairReport->buildResumeOptions());
            }
            if ($repairReport->stoppedBecause === AIConversationEmbeddingsRepairReport::STOPPED_FAILED) {
                $symfonyStyle->error($summaryLine . ' — ' . $repairReport->runError);
                return Command::FAILURE;
            }
            $symfonyStyle->success($summaryLine);
            return Command::SUCCESS;
        } catch (Throwable $repairError) {
            $symfonyStyle->error($repairError->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * The repair settings the options name.
     *
     * @param InputInterface $input
     * @return AIConversationEmbeddingsRepairSettings
     * @throws InvalidOptionException when `--target` names no known target
     */
    protected function buildSettingsFromInput(InputInterface $input): AIConversationEmbeddingsRepairSettings
    {
        $repairSettings = new AIConversationEmbeddingsRepairSettings();
        $target = (string)$input->getOption('target');
        if (!in_array($target, AIConversationEmbeddingsRepairSettings::TARGETS, true)) {
            throw new InvalidOptionException('--target must be one of: ' . implode(', ', AIConversationEmbeddingsRepairSettings::TARGETS));
        }
        $repairSettings->target = $target;
        $repairSettings->batchSize = max(1, (int)$input->getOption('batchSize'));
        $repairSettings->sleepMs = max(0, (int)$input->getOption('sleepMs'));
        $repairSettings->limit = max(0, (int)$input->getOption('limit'));
        $repairSettings->scanWindow = max(1, (int)$input->getOption('scanWindow'));
        $repairSettings->maxThreadsRunning = max(1, (int)$input->getOption('maxThreadsRunning'));
        $repairSettings->maxHistoryListLength = max(1, (int)$input->getOption('maxHistoryListLength'));
        $repairSettings->afterMessageEmbeddingId = max(0, (int)$input->getOption('afterMessageEmbeddingId'));
        $repairSettings->afterCompactionNodeId = max(0, (int)$input->getOption('afterCompactionNodeId'));
        $repairSettings->afterMemoryId = max(0, (int)$input->getOption('afterMemoryId'));
        $repairSettings->newestRowsOnly = max(0, (int)$input->getOption('newestRowsOnly'));
        $repairSettings->maxSeconds = max(0, (int)$input->getOption('maxSeconds'));
        $repairSettings->dryRun = (bool)$input->getOption('dryRun') || !(bool)$input->getOption('apply');
        return $repairSettings;
    }
}
