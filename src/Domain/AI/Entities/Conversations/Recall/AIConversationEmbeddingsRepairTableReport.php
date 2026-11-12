<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one repair run found and did on a table whose rows carry their own text and vector column — the compaction
 * nodes (`summaryText` → `summaryEmbedding`) and the account memories (`content` → `embedding`): the zero-vector rows
 * found, how many were re-embedded, had no text or failed, and the last id scanned (where a resumed run starts).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEmbeddingsRepairTableReport extends ValueObject
{
    /** @var string The figure prefix of this table in the summary (`compactionNodes`, `memories`). */
    public string $figurePrefix = '';

    /** @var int Rows whose vector is the zero vector. */
    public int $zeroRowsFound = 0;

    /** @var int Of those, rows whose text is longer than the first-attempt input bound (cut before embedding). */
    public int $rowsOverInputLimit = 0;

    public int $rowsReembedded = 0;

    /** @var int Rows without text — nothing to embed, left as they are. */
    public int $rowsWithoutText = 0;

    public int $rowsFailed = 0;

    /** @var int The highest id scanned — the `after…Id` option of a resumed run. */
    public int $lastId = 0;

    public static function forTable(string $figurePrefix, int $afterId): static
    {
        $tableReport = new static();
        $tableReport->figurePrefix = $figurePrefix;
        $tableReport->lastId = max(0, $afterId);
        return $tableReport;
    }

    /** @return array<string, int> the figures by name, prefixed */
    public function buildFigures(): array
    {
        return [
            $this->figurePrefix . 'ZeroRowsFound' => $this->zeroRowsFound,
            $this->figurePrefix . 'OverInputLimit' => $this->rowsOverInputLimit,
            $this->figurePrefix . 'Reembedded' => $this->rowsReembedded,
            $this->figurePrefix . 'WithoutText' => $this->rowsWithoutText,
            $this->figurePrefix . 'Failed' => $this->rowsFailed,
            $this->figurePrefix . 'LastId' => $this->lastId,
        ];
    }
}
