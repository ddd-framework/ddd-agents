<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The knobs of one run of `AIConversationEmbeddingsRepairService`: which tables, how many rows are repaired
 * between two pauses, how long the pause is, how many id values one scan query covers, where the run starts (an id per
 * table, or only the newest rows of each table — the hourly cron) and its time budget. A dry run is the default;
 * `$dryRun = false` writes.
 */
class AIConversationEmbeddingsRepairSettings extends ValueObject
{
    /** @var string Repair the message embedding rows and the compaction-node summary embeddings. */
    public const string TARGET_ALL = 'all';

    /** @var string Repair only the message embedding rows (`AIConversationMessageEmbeddings`). */
    public const string TARGET_MESSAGES = 'messages';

    /** @var string Repair only the compaction-node summary embeddings (`AIConversationCompactionNodes`). */
    public const string TARGET_NODES = 'nodes';

    /** @var string Repair only the account memory embeddings (the host's account memory table). */
    public const string TARGET_MEMORIES = 'memories';

    public const array TARGETS = [self::TARGET_ALL, self::TARGET_MESSAGES, self::TARGET_NODES, self::TARGET_MEMORIES];

    /**
     * @var int A positive time budget is capped here so a cron run ends inside the ticker's 360-second process timeout:
     * after the last budget check a run still finishes the row it started — up to two embedding calls of at most 30 s
     * each (the timeout of the host's embedding call, one retry on an
     * error answer) plus its writes. 200 s + that + the console boot stays below 300 s. A manual run without a budget
     * passes 0.
     */
    public const int MAXIMUM_SECONDS = 200;

    public string $target = self::TARGET_ALL;

    /** @var int Rows re-embedded (one embedding call each) between two pauses. */
    public int $batchSize = 25;

    /** @var int Pause after every batch and after every scan query, in milliseconds. */
    public int $sleepMs = 1000;

    /**
     * @var int Stop after this many rows were REPAIRED (re-embedded or deleted; found, in a dry run) — a row that keeps
     * failing is reported but never counted, `maxSeconds` is the hard bound; 0 = no limit.
     */
    public int $limit = 0;

    /** @var int Id values one scan query covers (`id > from AND id <= from + window`); bounds the rows a scan reads. */
    public int $scanWindow = 50000;

    /** @var int Wait while more statements than this execute on the server (writes only). */
    public int $maxThreadsRunning = 40;

    /** @var int Wait while the InnoDB purge backlog (history list length) is longer than this (writes only). */
    public int $maxHistoryListLength = 200000;

    /** @var int Start the message-embedding scan after this row id. */
    public int $afterMessageEmbeddingId = 0;

    /** @var int Start the compaction-node scan after this node id. */
    public int $afterCompactionNodeId = 0;

    /** @var int Start the memory scan after this memory id. */
    public int $afterMemoryId = 0;

    /**
     * @var int Scan only the newest this-many ids of each table (from `max(id) - n`, or the `after…Id` when that is
     * higher); 0 = the whole table. The hourly cron scans only what failed since the last runs.
     */
    public int $newestRowsOnly = 0;

    /**
     * @var int Time budget in seconds (0 = none); a positive budget is capped at {@see MAXIMUM_SECONDS}, so a cron run
     * stays below the ticker's 360-second process timeout.
     */
    public int $maxSeconds = 0;

    public bool $dryRun = true;

    public function includesMessages(): bool
    {
        return $this->target === self::TARGET_ALL || $this->target === self::TARGET_MESSAGES;
    }

    public function includesCompactionNodes(): bool
    {
        return $this->target === self::TARGET_ALL || $this->target === self::TARGET_NODES;
    }

    public function includesMemories(): bool
    {
        return $this->target === self::TARGET_ALL || $this->target === self::TARGET_MEMORIES;
    }

    /** The time budget that applies: 0 (none) stays 0, a positive budget never goes above {@see MAXIMUM_SECONDS}. Pure. */
    public function getEffectiveMaxSeconds(): int
    {
        return $this->maxSeconds > 0 ? min(self::MAXIMUM_SECONDS, $this->maxSeconds) : 0;
    }

    /** The id a table's scan starts after: the configured one, or later when only the newest rows are scanned. Pure. */
    public function findScanStartId(int $afterId, int $maxId): int
    {
        $startId = max(0, $afterId);
        if ($this->newestRowsOnly > 0) {
            $startId = max($startId, $maxId - $this->newestRowsOnly);
        }
        return $startId;
    }
}
