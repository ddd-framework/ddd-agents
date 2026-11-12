<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Texts\Embeddings;

use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\AI\Exceptions\Texts\Embeddings\TextEmbeddingNotGeneratedException;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\Service;
use Psr\Log\LogLevel;
use Throwable;

/**
 * The embedding port: turns a text into a {@see TextEmbedding} vector for the agent's similarity features (compaction
 * nodes, message embeddings, conversation search). The core never embeds by itself.
 *
 * The default generates nothing: {@see generateEmbeddingForText()} throws {@see TextEmbeddingNotGeneratedException},
 * which every core caller already absorbs (a compaction node keeps the zero vector, a message gets no embedding row, a
 * search runs its keyword leg only). An application with an embedding provider puts a same-path subclass under its own
 * root namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AITextEmbeddingsService::class)`.
 */
class AITextEmbeddingsService extends Service
{
    /**
     * The embedding vector of `$content` with `$dimensions` values (never an empty or all-zero vector). The default has
     * no provider and always throws.
     *
     * @param string $content The text to embed.
     * @param int $dimensions Vector dimensions (the Vector::DIMENSION_OPENAI_EMBEDDING_* constants).
     * @return TextEmbedding
     * @throws TextEmbeddingNotGeneratedException when no vector of `$dimensions` non-zero values was generated
     * @throws InternalErrorException
     */
    public function generateEmbeddingForText(
        string $content,
        int $dimensions = Vector::DIMENSION_OPENAI_EMBEDDING_SMALL
    ): TextEmbedding {
        throw new TextEmbeddingNotGeneratedException('no embedding provider is configured', strlen($content));
    }

    /**
     * Logs a failure of an embedding write or of a vector search that the caller degrades from instead of failing: a
     * WARNING on the issues channel with what the caller does instead (`$consequence`, e.g. "no embedding row written for
     * message 123"). A logging failure is swallowed — it must never break the write or search it reports on.
     *
     * @param Throwable $throwable
     * @param string $consequence
     * @param array<string, mixed> $context additional log fields
     * @return void
     */
    public function logEmbeddingFailure(Throwable $throwable, string $consequence, array $context = []): void
    {
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable(
                $throwable,
                LogLevel::WARNING,
                $context + ['consequence' => $consequence, 'reason' => $throwable instanceof TextEmbeddingNotGeneratedException ? $throwable->reason : $throwable->getMessage()]
            );
        } catch (Throwable) {
            error_log('AITextEmbeddingsService: ' . $consequence . ' — ' . $throwable->getMessage());
        }
    }
}
