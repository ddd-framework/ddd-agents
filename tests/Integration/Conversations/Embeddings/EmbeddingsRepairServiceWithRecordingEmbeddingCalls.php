<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations\Embeddings;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTarget;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingZeroVectorRow;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationZeroMessageEmbeddingRow;
use DDD\Domain\AI\Entities\Conversations\Recall\AIConversationEmbeddingsRepairSettings;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Services\Conversations\AIConversationEmbeddingsRepairService;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use Throwable;

/**
 * The embedding repair with everything that leaves the database replaced: the embedding call answers a vector of one
 * repeated value and records the text, the message writer records the message, the server is never busy, a pause
 * takes no time and a failure is recorded instead of logged. Every statement against the database is the service's.
 */
class EmbeddingsRepairServiceWithRecordingEmbeddingCalls extends AIConversationEmbeddingsRepairService
{
    /** @var float The value every element of an answered vector holds */
    public const float ANSWERED_VECTOR_VALUE = 0.5;

    /** @var string[] The texts an embedding was asked for, in order */
    public array $embeddedTexts = [];

    /** @var int[] The ids of the messages handed to the message writer, in order */
    public array $messageIdsHandedToTheWriter = [];

    /** @var string[] What a recorded failure said about its row, in order */
    public array $recordedFailureConsequences = [];

    /** The compare-and-set write of the service, callable from a test. */
    public function writeVectorOfRowIfStillZero(AgentEmbeddingRepairTarget $agentEmbeddingRepairTarget, int $rowId, TextEmbedding $embedding): void
    {
        $this->writeVectorIfStillZero($agentEmbeddingRepairTarget, $rowId, $embedding);
    }

    /**
     * The scan of one id window of a table with an embedding column, callable from a test.
     *
     * @return AgentEmbeddingZeroVectorRow[]
     */
    public function findZeroVectorRowsOfIdWindow(AgentEmbeddingRepairTarget $agentEmbeddingRepairTarget, int $afterId, int $upToId): array
    {
        return $this->findZeroVectorRowsInIdWindow($agentEmbeddingRepairTarget, $afterId, $upToId);
    }

    /**
     * The scan of one id window of the message embedding rows, callable from a test.
     *
     * @return AIConversationZeroMessageEmbeddingRow[]
     */
    public function findZeroMessageEmbeddingRowsOfIdWindow(int $afterRowId, int $upToRowId): array
    {
        return $this->findZeroMessageEmbeddingRowsInIdWindow($afterRowId, $upToRowId);
    }

    /** A vector of the stored dimension whose elements all hold `$elementValue`. */
    public static function buildVectorOfOneValue(float $elementValue): TextEmbedding
    {
        return new TextEmbedding(array_fill(0, Vector::DIMENSION_OPENAI_EMBEDDING_SMALL, $elementValue));
    }

    protected function generateEmbeddingForText(string $text): TextEmbedding
    {
        $this->embeddedTexts[] = $text;
        return self::buildVectorOfOneValue(self::ANSWERED_VECTOR_VALUE);
    }

    protected function createEmbeddingForMessage(AIConversationMessage $message): bool
    {
        $this->messageIdsHandedToTheWriter[] = (int)$message->id;
        return true;
    }

    protected function isDatabaseTooBusy(AIConversationEmbeddingsRepairSettings $settings): bool
    {
        return false;
    }

    protected function pauseMilliseconds(int $pauseMs): void
    {
    }

    protected function logRepairFailure(Throwable $repairError, string $consequence): void
    {
        $this->recordedFailureConsequences[] = $consequence . ' (' . $repairError->getMessage() . ')';
    }
}
