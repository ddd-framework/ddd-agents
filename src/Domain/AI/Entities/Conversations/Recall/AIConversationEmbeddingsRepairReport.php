<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one run of `AIConversationEmbeddingsRepairService` found and did (or, in a dry run, would do): the zero
 * vector rows per table, how each was resolved, the scan position reached per table and why the run stopped.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEmbeddingsRepairReport extends ValueObject
{
    public const string STOPPED_SCAN_COMPLETE = 'every row scanned';

    public const string STOPPED_LIMIT_REACHED = 'limit reached';

    public const string STOPPED_TIME_BUDGET = 'time budget used up';

    public const string STOPPED_FAILED = 'failed';

    public bool $dryRun = true;

    /** @var int Message embedding rows holding the zero vector that the scan found. */
    public int $zeroMessageEmbeddingRowsFound = 0;

    /** @var int Of those, rows whose message is a recall unit and gets (would get) a fresh vector. */
    public int $messageEmbeddingRowsToReembed = 0;

    /** @var int Of those, rows whose message is gone, empty or no TEXT message: deleted (would be). */
    public int $messageEmbeddingRowsWithoutRecallUnit = 0;

    /** @var int Of the rows to re-embed, messages longer than the first-attempt input bound (cut before embedding). */
    public int $messagesOverInputLimit = 0;

    public int $messageEmbeddingRowsReembedded = 0;

    public int $messageEmbeddingRowsDeleted = 0;

    /** @var int The embedding call yielded no vector: the writer deleted the zero row and logged it. */
    public int $messageEmbeddingRowsWithoutVectorAgain = 0;

    public int $messageEmbeddingRowsFailed = 0;

    /** @var int The highest message-embedding row id scanned — `--afterMessageEmbeddingId` of a resumed run. */
    public int $lastMessageEmbeddingId = 0;

    /** The compaction nodes (`summaryEmbedding`). */
    public AIConversationEmbeddingsRepairTableReport $compactionNodes;

    /** The account memories (`embedding`). */
    public AIConversationEmbeddingsRepairTableReport $memories;

    public int $scanQueries = 0;

    public int $pausesForServerLoad = 0;

    public int $runSeconds = 0;

    public string $stoppedBecause = self::STOPPED_SCAN_COMPLETE;

    /** @var string|null The message of the Throwable that ended the run ({@see STOPPED_FAILED}). */
    public ?string $runError = null;

    /** The report of a run that starts where the settings say (dry run or not, the start id per table). */
    public static function startedWith(AIConversationEmbeddingsRepairSettings $settings): static
    {
        $report = new static();
        $report->dryRun = $settings->dryRun;
        $report->lastMessageEmbeddingId = max(0, $settings->afterMessageEmbeddingId);
        $report->compactionNodes = AIConversationEmbeddingsRepairTableReport::forTable('compactionNodes', $settings->afterCompactionNodeId);
        $report->memories = AIConversationEmbeddingsRepairTableReport::forTable('memories', $settings->afterMemoryId);
        return $report;
    }

    /**
     * Rows the limit counts: found in a dry run; in a writing run only rows actually REPAIRED (re-embedded, or deleted
     * for want of a recall unit). A row that keeps failing (or stays without a vector, or has no text) is reported but
     * never counted — else rows that fail every hour would use up the cron's limit before it reaches the repairable
     * ones; `--maxSeconds` stays the hard bound of a run.
     */
    public function countRowsTowardsLimit(): int
    {
        if ($this->dryRun) {
            return $this->zeroMessageEmbeddingRowsFound + $this->compactionNodes->zeroRowsFound + $this->memories->zeroRowsFound;
        }
        return $this->messageEmbeddingRowsReembedded + $this->messageEmbeddingRowsDeleted
            + $this->compactionNodes->rowsReembedded + $this->memories->rowsReembedded;
    }

    /** The options a restarted run resumes with — the last id scanned per table. */
    public function buildResumeOptions(): string
    {
        return "--afterMessageEmbeddingId={$this->lastMessageEmbeddingId}"
            . " --afterCompactionNodeId={$this->compactionNodes->lastId}"
            . " --afterMemoryId={$this->memories->lastId}";
    }

    /** @return array<string, int|string> every figure by name, for the command's summary table */
    public function buildFigures(): array
    {
        return [
            'zeroMessageEmbeddingRowsFound' => $this->zeroMessageEmbeddingRowsFound,
            'messageEmbeddingRowsToReembed' => $this->messageEmbeddingRowsToReembed,
            'messageEmbeddingRowsWithoutRecallUnit' => $this->messageEmbeddingRowsWithoutRecallUnit,
            'messagesOverInputLimit' => $this->messagesOverInputLimit,
            'messageEmbeddingRowsReembedded' => $this->messageEmbeddingRowsReembedded,
            'messageEmbeddingRowsDeleted' => $this->messageEmbeddingRowsDeleted,
            'messageEmbeddingRowsWithoutVectorAgain' => $this->messageEmbeddingRowsWithoutVectorAgain,
            'messageEmbeddingRowsFailed' => $this->messageEmbeddingRowsFailed,
            'lastMessageEmbeddingId' => $this->lastMessageEmbeddingId,
        ] + $this->compactionNodes->buildFigures() + $this->memories->buildFigures() + [
            'scanQueries' => $this->scanQueries,
            'pausesForServerLoad' => $this->pausesForServerLoad,
            'runSeconds' => $this->runSeconds,
            'stoppedBecause' => $this->stoppedBecause,
        ];
    }
}
