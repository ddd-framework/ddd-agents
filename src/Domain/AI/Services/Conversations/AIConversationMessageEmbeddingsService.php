<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Embeddings\AIConversationMessageEmbedding;
use DDD\Domain\AI\Exceptions\Texts\Embeddings\TextEmbeddingNotGeneratedException;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbeddings;
use DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use ReflectionClass;

/**
 * EntitiesService for {@see AIConversationMessageEmbedding} — owns creating a message's recall embedding ROW (the
 * redesign that replaced the per-message `messageEmbedding` column). A row exists ONLY for a recall-worthy message
 * (TEXT + non-empty text); every other message simply has no embedding row, so "not embedded" = no row (a clean LEFT
 * JOIN / `IS NULL`), never a zero-vector sentinel.
 *
 * @method AIConversationMessageEmbedding find(string|int|null $entityId, bool $useEntityRegistrCache = true)
 * @method DBAIConversationMessageEmbedding getEntityRepoClassInstance()
 * @method DBAIConversationMessageEmbeddings getEntitySetRepoClassInstance()
 */
class AIConversationMessageEmbeddingsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AIConversationMessageEmbedding::class;

    /** Whether a message is a recall unit that should carry an embedding row (TEXT + persisted + non-empty text). */
    public function isEmbeddable(AIConversationMessage $message): bool
    {
        return ($message->messageType ?? null) === AIConversationMessage::MESSAGE_TEXT
            && isset($message->id)
            && trim($message->message ?? '') !== '';
    }

    /**
     * Generate the message's embedding and UPSERT its row (one per message, keyed by `aiConversationMessageId`). No-op
     * for a non-embeddable message. The vector lives in {@see AITextEmbeddingsService} (Argus connects only there),
     * which also cuts an over-long message to the model's input limit.
     *
     * When no vector comes back ({@see TextEmbeddingNotGeneratedException}) NO row is written — "not embedded" stays
     * "no row", never a zero vector (the column's default) that breaks the cosine search of the whole account. A row
     * of this message that holds no vector (written before this guard) is deleted then, so the backfill finds the
     * message again; a row with a real, older vector stays. The failure is logged as a warning.
     *
     * @return bool true when the row holds a fresh vector, false when the message is not embeddable or no vector came back
     * @throws InternalErrorException
     */
    public function createEmbeddingForMessage(AIConversationMessage $message): bool
    {
        if (!$this->isEmbeddable($message)) {
            return false;
        }
        /** @var AITextEmbeddingsService $embeddingsService */
        $embeddingsService = DDDService::instance()->getService(AITextEmbeddingsService::class);
        $existingEmbedding = $this->findEmbeddingRowOfMessage($message);
        try {
            $vector = $embeddingsService->generateEmbeddingForText(trim((string)$message->message));
        } catch (TextEmbeddingNotGeneratedException $embeddingNotGenerated) {
            $zeroVectorRowNote = $this->deleteRowWithoutVector($existingEmbedding);
            $embeddingsService->logEmbeddingFailure(
                $embeddingNotGenerated,
                'no embedding row written for message ' . $message->id . $zeroVectorRowNote,
                ['aiConversationMessageId' => (int)$message->id, 'aiConversationId' => $message->aiConversationId ?? null]
            );
            return false;
        }

        // Upsert: a redelivered async embed (or a re-embed) targets the SAME row (UNIQUE aiConversationMessageId).
        $embedding = $existingEmbedding
            ?? (new ReflectionClass(AIConversationMessageEmbedding::class))->newInstanceWithoutConstructor();
        $embedding->aiConversationMessageId = (int)$message->id;
        $embedding->embedding = $vector;
        $embedding->update();
        return true;
    }

    /**
     * Delete the message's row when it holds no vector (a row with a real, older vector stays). Never throws: a
     * failed delete is reported in the returned note, so the caller's warning is always written.
     *
     * @return string the note for the warning: empty, "zero-vector row was deleted" or why the delete failed
     */
    protected function deleteRowWithoutVector(?AIConversationMessageEmbedding $existingEmbedding): string
    {
        if ($existingEmbedding === null || $existingEmbedding->hasVector()) {
            return '';
        }
        try {
            $existingEmbedding->delete();
            return ' (its zero-vector row was deleted)';
        } catch (\Throwable $deleteError) {
            return ' (its zero-vector row could not be deleted: ' . mb_substr($deleteError->getMessage(), 0, 200) . ')';
        }
    }

    /** The message's embedding row, or null — the override point of the tests. */
    protected function findEmbeddingRowOfMessage(AIConversationMessage $message): ?AIConversationMessageEmbedding
    {
        return $this->getEntitySetRepoClassInstance()->findByMessageId((int)$message->id);
    }
}
