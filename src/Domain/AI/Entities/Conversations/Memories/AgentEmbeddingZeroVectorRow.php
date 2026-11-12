<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE row of an embedding repair target's table ({@see AgentEmbeddingRepairTarget}) whose vector column holds the
 * zero vector, with the byte length of its text. Built from one row of the repair's id-window scan
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationEmbeddingsRepairService}).
 *
 * @phpstan-consistent-constructor
 */
class AgentEmbeddingZeroVectorRow extends ValueObject
{
    /** @var int The id of the row */
    public int $rowId = 0;

    /** @var int The byte length of the row's text; 0 for a row without text */
    public int $textBytes = 0;

    /**
     * @param array $scanRow One row of the id-window scan (`rowId`, `textBytes`)
     * @return static
     */
    public static function fromScanRow(array $scanRow): static
    {
        $zeroVectorRow = new static();
        $zeroVectorRow->rowId = (int)$scanRow['rowId'];
        $zeroVectorRow->textBytes = (int)($scanRow['textBytes'] ?? 0);
        return $zeroVectorRow;
    }

    /** @return bool Whether the row's text is longer than the first embedding attempt accepts */
    public function isOverFirstAttemptInputLimit(): bool
    {
        return $this->textBytes > TextEmbedding::FIRST_ATTEMPT_MAX_INPUT_BYTES;
    }
}
