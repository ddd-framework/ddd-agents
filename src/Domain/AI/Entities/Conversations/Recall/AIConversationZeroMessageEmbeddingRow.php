<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE message embedding row that holds the zero vector, with what the embeddings repair needs of its message to
 * decide between re-embedding and deleting the row. Built from one row of the repair's id-window scan
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationEmbeddingsRepairService}); no message is loaded for it.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationZeroMessageEmbeddingRow extends ValueObject
{
    /** @var int The id of the message embedding row */
    public int $embeddingRowId = 0;

    /** @var int|null The message id the embedding row points to */
    public ?int $messageId = null;

    /** @var int|null The id of that message when it still exists; null for a row whose message is gone */
    public ?int $existingMessageId = null;

    /** @var string|null The message's type; null for a row whose message is gone */
    public ?string $messageType = null;

    /** @var int The byte length of the message's text; 0 for a message without text */
    public int $messageBytes = 0;

    /** @var bool Whether the message has text that is more than whitespace */
    public bool $hasText = false;

    /**
     * @param array $scanRow One row of the id-window scan (`embeddingRowId`, `messageId`, `existingMessageId`,
     *        `messageType`, `messageBytes`, `hasText`)
     * @return static
     */
    public static function fromScanRow(array $scanRow): static
    {
        $zeroMessageEmbeddingRow = new static();
        $zeroMessageEmbeddingRow->embeddingRowId = (int)$scanRow['embeddingRowId'];
        $zeroMessageEmbeddingRow->messageId = isset($scanRow['messageId']) ? (int)$scanRow['messageId'] : null;
        $zeroMessageEmbeddingRow->existingMessageId = isset($scanRow['existingMessageId']) ? (int)$scanRow['existingMessageId'] : null;
        $zeroMessageEmbeddingRow->messageType = isset($scanRow['messageType']) ? (string)$scanRow['messageType'] : null;
        $zeroMessageEmbeddingRow->messageBytes = (int)($scanRow['messageBytes'] ?? 0);
        $zeroMessageEmbeddingRow->hasText = (int)($scanRow['hasText'] ?? 0) === 1;
        return $zeroMessageEmbeddingRow;
    }

    /**
     * Whether the row's message is a recall unit (it exists, is a TEXT message and has text) — the same test as
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessageEmbeddingsService::isEmbeddable()}, read from
     * the scan row.
     *
     * @return bool
     */
    public function isRecallUnit(): bool
    {
        return $this->existingMessageId !== null
            && $this->messageType === AIConversationMessage::MESSAGE_TEXT
            && $this->hasText;
    }

    /** @return bool Whether the message's text is longer than the first embedding attempt accepts */
    public function isOverFirstAttemptInputLimit(): bool
    {
        return $this->messageBytes > TextEmbedding::FIRST_ATTEMPT_MAX_INPUT_BYTES;
    }
}
