<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTarget;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingZeroVectorRow;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationEmbeddingsRepairReport;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationEmbeddingsRepairSettings;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationEmbeddingsRepairTableReport;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationZeroMessageEmbeddingRow;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Domain\AI\Repo\DB\Conversations\Compaction\DBAIConversationCompactionNodeModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessages;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddingModel;
use DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Repairs the recall embeddings that hold the ZERO vector (run by a console command of the application). A zero vector
 * is the `vector(1536) NOT NULL` column default a row keeps when its embedding call answered no vector — before the
 * input bound a message of 17k+ characters exceeded the model's 8,192-token input; an outage does the same at any time.
 * Such a message row made MariaDB's cosine distance fail the search of the whole account.
 *
 * Three tables:
 * - message embedding rows (`AIConversationMessageEmbeddings`): a row whose message is a recall unit is
 *   re-embedded through the writer ({@see AIConversationMessageEmbeddingsService::createEmbeddingForMessage()}, which
 *   updates the row in place, or deletes it and logs when the call yields no vector again); a row whose message is
 *   gone, empty or no TEXT message is deleted — "not embedded" is "no row". The writer no longer leaves zero rows.
 * - compaction-node summary embeddings (`AIConversationCompactionNodes.summaryEmbedding`) and account memory
 *   embeddings (the `embedding` column of the host's account memory table): both are still written without a vector when the call fails (a
 *   node and a memory are worth more than their vector), so they are what the hourly cron heals. Each is re-embedded
 *   from its own text and written with a compare-and-set `UPDATE … WHERE <vector> = <zero vector>`; a row without text
 *   is left alone.
 *
 * Polite: the scan reads id windows (`id > from AND id <= from + scanWindow`), so one query reads a bounded slice of a
 * table; a pause follows every scan query and every batch of repairs; writes wait while the server is busy (the
 * Threads_running / history-list guard of the background rewrites); a time budget and a row limit end the run early.
 * Resumable by construction — a repaired row no longer holds the zero vector — and by the `after…Id` per table, which
 * the report carries also when the run fails. A dry run (the default) counts and classifies only.
 *
 * Every statement is DQL over the generated models: the vector functions of the framework exist as DQL functions for
 * each database engine. The text length of the scan is the database's `LENGTH` — bytes on MariaDB, characters on
 * PostgreSQL, where the "over the input limit" figures are therefore a lower bound.
 */
class AIConversationEmbeddingsRepairService extends Service
{
    /** @var int Pause while the server is busy (milliseconds). */
    protected const int SERVER_BUSY_PAUSE_MS = 5000;

    /** @var float When the running repair started (for the time budget). */
    protected float $runStartedAtSeconds = 0.0;

    /**
     * Scan the tables the settings select and repair — or, in a dry run, count — every zero-vector row. Never throws:
     * a failure ends the run with {@see AIConversationEmbeddingsRepairReport::STOPPED_FAILED}, the message in
     * `runError` and the last id per table, so a restart resumes there.
     *
     * @param AIConversationProgressReporterInterface|null $progressReporter one line per scanned id window
     */
    public function repairZeroEmbeddings(AIConversationEmbeddingsRepairSettings $settings, ?AIConversationProgressReporterInterface $progressReporter = null): AIConversationEmbeddingsRepairReport
    {
        $this->runStartedAtSeconds = $this->currentSeconds();
        $report = AIConversationEmbeddingsRepairReport::startedWith($settings);
        try {
            if ($settings->includesMessages()) {
                $this->repairMessageEmbeddingRows($settings, $report, $progressReporter);
            }
            if ($settings->includesCompactionNodes() && !$this->shouldStop($settings, $report)) {
                $this->repairVectorColumnTable(
                    AgentEmbeddingRepairTarget::forModel(DBAIConversationCompactionNodeModel::class, 'summaryText', 'summaryEmbedding'),
                    $report->compactionNodes,
                    $settings,
                    $report,
                    $progressReporter
                );
            }
            if ($settings->includesMemories() && !$this->shouldStop($settings, $report)) {
                $this->repairMemoryEmbeddingTables($settings, $report, $progressReporter);
            }
            if ($this->isLimitReached($settings, $report)) {
                $report->stoppedBecause = AIConversationEmbeddingsRepairReport::STOPPED_LIMIT_REACHED;
            } elseif ($this->isTimeBudgetUsedUp($settings)) {
                $report->stoppedBecause = AIConversationEmbeddingsRepairReport::STOPPED_TIME_BUDGET;
            }
        } catch (Throwable $runError) {
            $report->stoppedBecause = AIConversationEmbeddingsRepairReport::STOPPED_FAILED;
            $report->runError = $runError->getMessage();
            $this->logRepairFailure($runError, 'the zero-embedding repair stopped; resume with ' . $report->buildResumeOptions());
        }
        $report->runSeconds = (int)round($this->currentSeconds() - $this->runStartedAtSeconds);
        return $report;
    }

    /**
     * The memory half: every table the memory component names ({@see AgentMemoriesService::getEmbeddingRepairTargets()};
     * none without a memory component). Their counts and resume id go to the report's memory table report — a memory
     * component contributes one table.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function repairMemoryEmbeddingTables(
        AIConversationEmbeddingsRepairSettings $settings,
        AIConversationEmbeddingsRepairReport $report,
        ?AIConversationProgressReporterInterface $progressReporter
    ): void {
        /** @var AgentMemoriesService $agentMemoriesService */
        $agentMemoriesService = DDDService::instance()->getService(AgentMemoriesService::class);
        foreach ($agentMemoriesService->getEmbeddingRepairTargets()->getElements() as $agentEmbeddingRepairTarget) {
            if ($this->shouldStop($settings, $report)) {
                return;
            }
            $this->repairVectorColumnTable($agentEmbeddingRepairTarget, $report->memories, $settings, $report, $progressReporter);
        }
    }

    /**
     * The message-embedding half: id window by id window, the zero rows classified and repaired in batches.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function repairMessageEmbeddingRows(
        AIConversationEmbeddingsRepairSettings $settings,
        AIConversationEmbeddingsRepairReport $report,
        ?AIConversationProgressReporterInterface $progressReporter
    ): void {
        $embeddingTableName = DBAIConversationMessageEmbeddingModel::getTableName();
        $maxRowId = $this->findMaxId(DBAIConversationMessageEmbeddingModel::class);
        $fromRowId = $settings->findScanStartId($settings->afterMessageEmbeddingId, $maxRowId);
        $report->lastMessageEmbeddingId = max($report->lastMessageEmbeddingId, $fromRowId);
        while ($fromRowId < $maxRowId) {
            if ($this->shouldStop($settings, $report)) {
                return;
            }
            $toRowId = min($maxRowId, $fromRowId + max(1, $settings->scanWindow));
            $zeroRows = $this->findZeroMessageEmbeddingRowsInIdWindow($fromRowId, $toRowId);
            $report->scanQueries++;
            foreach (array_chunk($zeroRows, max(1, $settings->batchSize)) as $batchRows) {
                if (!$settings->dryRun) {
                    $this->waitWhileDatabaseIsBusy($settings, $report);
                }
                foreach ($batchRows as $zeroRow) {
                    if ($this->shouldStop($settings, $report)) {
                        return;
                    }
                    $this->repairMessageEmbeddingRow($zeroRow, $settings, $report);
                    $report->lastMessageEmbeddingId = $zeroRow->embeddingRowId;
                }
                if (!$settings->dryRun) {
                    $this->pauseMilliseconds($settings->sleepMs);
                }
            }
            $report->lastMessageEmbeddingId = $toRowId;
            $progressReporter?->reportProgress($this->buildProgressLine($embeddingTableName, $toRowId, $maxRowId, $report));
            $this->pauseMilliseconds($settings->sleepMs);
            $fromRowId = $toRowId;
        }
    }

    /**
     * One zero message-embedding row: classified (always) and, in a writing run, re-embedded or deleted.
     *
     * @param AIConversationZeroMessageEmbeddingRow $zeroRow
     * @param AIConversationEmbeddingsRepairSettings $settings
     * @param AIConversationEmbeddingsRepairReport $report
     * @return void
     */
    protected function repairMessageEmbeddingRow(AIConversationZeroMessageEmbeddingRow $zeroRow, AIConversationEmbeddingsRepairSettings $settings, AIConversationEmbeddingsRepairReport $report): void
    {
        $report->zeroMessageEmbeddingRowsFound++;
        $isRecallUnit = $zeroRow->isRecallUnit();
        if (!$isRecallUnit) {
            $report->messageEmbeddingRowsWithoutRecallUnit++;
        } else {
            $report->messageEmbeddingRowsToReembed++;
            if ($zeroRow->isOverFirstAttemptInputLimit()) {
                $report->messagesOverInputLimit++;
            }
        }
        if ($settings->dryRun) {
            return;
        }
        try {
            $message = $isRecallUnit ? $this->findMessage((int)$zeroRow->messageId) : null;
            if ($message === null) {
                $this->deleteZeroMessageEmbeddingRow($zeroRow->embeddingRowId);
                $report->messageEmbeddingRowsDeleted++;
                return;
            }
            if ($this->createEmbeddingForMessage($message)) {
                $report->messageEmbeddingRowsReembedded++;
            } else {
                $report->messageEmbeddingRowsWithoutVectorAgain++;
            }
        } catch (Throwable $repairError) {
            $report->messageEmbeddingRowsFailed++;
            $this->logRepairFailure($repairError, 'message embedding row ' . $zeroRow->embeddingRowId . ' left as it is');
        }
    }

    /**
     * A table whose rows carry their own text and vector column (compaction nodes, memories): id window by id window,
     * the zero-vector rows re-embedded from their text in batches.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function repairVectorColumnTable(
        AgentEmbeddingRepairTarget $agentEmbeddingRepairTarget,
        AIConversationEmbeddingsRepairTableReport $tableReport,
        AIConversationEmbeddingsRepairSettings $settings,
        AIConversationEmbeddingsRepairReport $report,
        ?AIConversationProgressReporterInterface $progressReporter
    ): void {
        $maxId = $this->findMaxId($agentEmbeddingRepairTarget->modelClassName);
        $fromId = $settings->findScanStartId($tableReport->lastId, $maxId);
        $tableReport->lastId = max($tableReport->lastId, $fromId);
        while ($fromId < $maxId) {
            if ($this->shouldStop($settings, $report)) {
                return;
            }
            $toId = min($maxId, $fromId + max(1, $settings->scanWindow));
            $zeroRows = $this->findZeroVectorRowsInIdWindow($agentEmbeddingRepairTarget, $fromId, $toId);
            $report->scanQueries++;
            foreach (array_chunk($zeroRows, max(1, $settings->batchSize)) as $batchRows) {
                if (!$settings->dryRun) {
                    $this->waitWhileDatabaseIsBusy($settings, $report);
                }
                foreach ($batchRows as $zeroRow) {
                    if ($this->shouldStop($settings, $report)) {
                        return;
                    }
                    $this->repairVectorColumnRow($agentEmbeddingRepairTarget, $zeroRow, $tableReport, $settings);
                    $tableReport->lastId = $zeroRow->rowId;
                }
                if (!$settings->dryRun) {
                    $this->pauseMilliseconds($settings->sleepMs);
                }
            }
            $tableReport->lastId = $toId;
            $progressReporter?->reportProgress($this->buildProgressLine($agentEmbeddingRepairTarget->getTableName(), $toId, $maxId, $report));
            $this->pauseMilliseconds($settings->sleepMs);
            $fromId = $toId;
        }
    }

    /**
     * One zero-vector row of such a table: counted (always) and, in a writing run, re-embedded from its text.
     *
     * @param AgentEmbeddingRepairTarget $agentEmbeddingRepairTarget
     * @param AgentEmbeddingZeroVectorRow $zeroRow
     * @param AIConversationEmbeddingsRepairTableReport $tableReport
     * @param AIConversationEmbeddingsRepairSettings $settings
     * @return void
     */
    protected function repairVectorColumnRow(
        AgentEmbeddingRepairTarget $agentEmbeddingRepairTarget,
        AgentEmbeddingZeroVectorRow $zeroRow,
        AIConversationEmbeddingsRepairTableReport $tableReport,
        AIConversationEmbeddingsRepairSettings $settings
    ): void {
        $tableReport->zeroRowsFound++;
        if ($zeroRow->isOverFirstAttemptInputLimit()) {
            $tableReport->rowsOverInputLimit++;
        }
        if ($settings->dryRun) {
            return;
        }
        $rowId = $zeroRow->rowId;
        try {
            $text = trim($this->findRowText($agentEmbeddingRepairTarget, $rowId));
            if ($text === '') {
                $tableReport->rowsWithoutText++;
                return;
            }
            $this->writeVectorIfStillZero($agentEmbeddingRepairTarget, $rowId, $this->generateEmbeddingForText($text));
            $tableReport->rowsReembedded++;
        } catch (Throwable $repairError) {
            $tableReport->rowsFailed++;
            $this->logRepairFailure(
                $repairError,
                $agentEmbeddingRepairTarget->getTableName() . " row $rowId keeps its zero " . $agentEmbeddingRepairTarget->vectorColumnName
            );
        }
    }

    protected function shouldStop(AIConversationEmbeddingsRepairSettings $settings, AIConversationEmbeddingsRepairReport $report): bool
    {
        return $this->isLimitReached($settings, $report) || $this->isTimeBudgetUsedUp($settings);
    }

    protected function isLimitReached(AIConversationEmbeddingsRepairSettings $settings, AIConversationEmbeddingsRepairReport $report): bool
    {
        return $settings->limit > 0 && $report->countRowsTowardsLimit() >= $settings->limit;
    }

    /**
     * Whether the run's capped budget ({@see AIConversationEmbeddingsRepairSettings::getEffectiveMaxSeconds()}) is used up.
     *
     * @phpstan-impure reads the clock
     */
    protected function isTimeBudgetUsedUp(AIConversationEmbeddingsRepairSettings $settings): bool
    {
        $effectiveMaxSeconds = $settings->getEffectiveMaxSeconds();
        return $effectiveMaxSeconds > 0 && ($this->currentSeconds() - $this->runStartedAtSeconds) >= $effectiveMaxSeconds;
    }

    protected function waitWhileDatabaseIsBusy(AIConversationEmbeddingsRepairSettings $settings, AIConversationEmbeddingsRepairReport $report): void
    {
        while ($this->isDatabaseTooBusy($settings) && !$this->isTimeBudgetUsedUp($settings)) {
            $report->pausesForServerLoad++;
            $this->pauseMilliseconds(self::SERVER_BUSY_PAUSE_MS);
        }
    }

    protected function buildProgressLine(string $tableName, int $scannedUpToId, int $maxId, AIConversationEmbeddingsRepairReport $report): string
    {
        return ($report->dryRun ? '[dry run] ' : '')
            . "$tableName scanned up to id $scannedUpToId of $maxId — zero rows found: {$report->zeroMessageEmbeddingRowsFound} message embeddings,"
            . " {$report->compactionNodes->zeroRowsFound} nodes, {$report->memories->zeroRowsFound} memories"
            . ($report->dryRun ? '' : " — repaired: {$report->messageEmbeddingRowsReembedded} messages, {$report->compactionNodes->rowsReembedded} nodes,"
                . " {$report->memories->rowsReembedded} memories; deleted: {$report->messageEmbeddingRowsDeleted}");
    }

    /**
     * The highest id of the table of the generated model `$modelClassName`; 0 for an empty table.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findMaxId(string $modelClassName): int
    {
        return (int)$this->getEntityManager()->createQuery("SELECT MAX(scannedRow.id) FROM $modelClassName scannedRow")->getSingleScalarResult();
    }

    /**
     * The zero-vector message embedding rows of one id window, each with what the classification needs of its message.
     *
     * @param int $afterRowId
     * @param int $upToRowId
     * @return AIConversationZeroMessageEmbeddingRow[]
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findZeroMessageEmbeddingRowsInIdWindow(int $afterRowId, int $upToRowId): array
    {
        $scanRows = $this->getEntityManager()->createQuery(
            'SELECT embeddingRow.id AS embeddingRowId, embeddingRow.aiConversationMessageId AS messageId, message.id AS existingMessageId,'
            . ' message.messageType AS messageType, COALESCE(OCTET_LENGTH(message.message), 0) AS messageBytes,'
            . " CASE WHEN message.message IS NOT NULL AND TRIM(message.message) <> '' THEN 1 ELSE 0 END AS hasText"
            . ' FROM ' . DBAIConversationMessageEmbeddingModel::class . ' embeddingRow LEFT JOIN embeddingRow.aiConversationMessage message'
            . ' WHERE embeddingRow.id > :afterRowId AND embeddingRow.id <= :upToRowId AND embeddingRow.embedding = VEC_FROM_TEXT(:zeroVector)'
            . ' ORDER BY embeddingRow.id'
        )->setParameters(['afterRowId' => $afterRowId, 'upToRowId' => $upToRowId, 'zeroVector' => self::buildZeroVectorText()])->getScalarResult();
        $zeroRows = [];
        foreach ($scanRows as $scanRow) {
            $zeroRows[] = AIConversationZeroMessageEmbeddingRow::fromScanRow($scanRow);
        }
        return $zeroRows;
    }

    /**
     * Delete one message embedding row — only while it still holds the zero vector (a parallel writer may have
     * embedded it meanwhile).
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function deleteZeroMessageEmbeddingRow(int $embeddingRowId): void
    {
        $this->getEntityManager()->createQuery(
            'DELETE FROM ' . DBAIConversationMessageEmbeddingModel::class . ' embeddingRow'
            . ' WHERE embeddingRow.id = :id AND embeddingRow.embedding = VEC_FROM_TEXT(:zeroVector)'
        )->setParameters(['id' => $embeddingRowId, 'zeroVector' => self::buildZeroVectorText()])->execute();
    }

    protected function findMessage(int $messageId): ?AIConversationMessage
    {
        $message = AIConversationMessage::getService()->find($messageId, false);
        return $message instanceof AIConversationMessage ? $message : null;
    }

    /** The writer: re-embeds the message and updates its row in place (false: no vector, the zero row deleted). */
    protected function createEmbeddingForMessage(AIConversationMessage $message): bool
    {
        /** @var AIConversationMessageEmbeddingsService $aIConversationMessageEmbeddingsService */
        $aIConversationMessageEmbeddingsService = DDDService::instance()->getService(AIConversationMessageEmbeddingsService::class);
        return $aIConversationMessageEmbeddingsService->createEmbeddingForMessage($message);
    }

    /**
     * The rows of one id window whose vector column is the zero vector, with the byte length of their text.
     *
     * @param AgentEmbeddingRepairTarget $agentEmbeddingRepairTarget
     * @param int $afterId
     * @param int $upToId
     * @return AgentEmbeddingZeroVectorRow[]
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findZeroVectorRowsInIdWindow(AgentEmbeddingRepairTarget $agentEmbeddingRepairTarget, int $afterId, int $upToId): array
    {
        $scanRows = $this->getEntityManager()->createQuery(
            "SELECT scannedRow.id AS rowId, COALESCE(OCTET_LENGTH(scannedRow.{$agentEmbeddingRepairTarget->textColumnName}), 0) AS textBytes"
            . " FROM {$agentEmbeddingRepairTarget->modelClassName} scannedRow"
            . ' WHERE scannedRow.id > :afterId AND scannedRow.id <= :upToId'
            . " AND scannedRow.{$agentEmbeddingRepairTarget->vectorColumnName} = VEC_FROM_TEXT(:zeroVector) ORDER BY scannedRow.id"
        )->setParameters(['afterId' => $afterId, 'upToId' => $upToId, 'zeroVector' => self::buildZeroVectorText()])->getScalarResult();
        $zeroRows = [];
        foreach ($scanRows as $scanRow) {
            $zeroRows[] = AgentEmbeddingZeroVectorRow::fromScanRow($scanRow);
        }
        return $zeroRows;
    }

    /**
     * The text of one row of the target's table; empty for a row that is gone or holds no text.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findRowText(AgentEmbeddingRepairTarget $agentEmbeddingRepairTarget, int $rowId): string
    {
        $storedTexts = $this->getEntityManager()->createQuery(
            "SELECT scannedRow.{$agentEmbeddingRepairTarget->textColumnName} FROM {$agentEmbeddingRepairTarget->modelClassName} scannedRow"
            . ' WHERE scannedRow.id = :id'
        )->setParameters(['id' => $rowId])->getSingleColumnResult();
        $storedText = $storedTexts[0] ?? null;
        return is_string($storedText) ? $storedText : '';
    }

    /** The generator with the input bound — throws when no vector comes back. */
    protected function generateEmbeddingForText(string $text): TextEmbedding
    {
        /** @var AITextEmbeddingsService $aITextEmbeddingsService */
        $aITextEmbeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
        return $aITextEmbeddingsService->generateEmbeddingForText($text);
    }

    /**
     * Compare-and-set: the row's vector is written only while it still holds the zero vector.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function writeVectorIfStillZero(AgentEmbeddingRepairTarget $agentEmbeddingRepairTarget, int $rowId, TextEmbedding $embedding): void
    {
        $vectorColumnName = $agentEmbeddingRepairTarget->vectorColumnName;
        $this->getEntityManager()->createQuery(
            "UPDATE {$agentEmbeddingRepairTarget->modelClassName} scannedRow SET scannedRow.$vectorColumnName = VEC_FROM_TEXT(:embedding)"
            . " WHERE scannedRow.id = :id AND scannedRow.$vectorColumnName = VEC_FROM_TEXT(:zeroVector)"
        )->setParameters([
            // the JSON array text the vector column type writes too (VectorType::convertToDatabaseValue)
            'embedding' => json_encode(array_map('floatval', array_values($embedding->vectorValues ?? [])), JSON_THROW_ON_ERROR),
            'id' => $rowId,
            'zeroVector' => self::buildZeroVectorText(),
        ])->execute();
    }

    protected static function buildZeroVectorText(): string
    {
        return DBAIConversationMessages::buildZeroVectorText(Vector::DIMENSION_OPENAI_EMBEDDING_SMALL);
    }

    protected function logRepairFailure(Throwable $repairError, string $consequence): void
    {
        /** @var AITextEmbeddingsService $aITextEmbeddingsService */
        $aITextEmbeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
        $aITextEmbeddingsService->logEmbeddingFailure($repairError, $consequence);
    }

    /** @phpstan-impure reads the live server status */
    protected function isDatabaseTooBusy(AIConversationEmbeddingsRepairSettings $settings): bool
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService->isDatabaseTooBusyForBackgroundWrites($settings->maxThreadsRunning, $settings->maxHistoryListLength);
    }

    /** @phpstan-impure the wall clock */
    protected function currentSeconds(): float
    {
        return microtime(true);
    }

    protected function pauseMilliseconds(int $pauseMs): void
    {
        if ($pauseMs > 0) {
            usleep($pauseMs * 1000);
        }
    }

    protected function getEntityManager(): DoctrineEntityManager
    {
        return EntityManagerFactory::getInstance();
    }
}
