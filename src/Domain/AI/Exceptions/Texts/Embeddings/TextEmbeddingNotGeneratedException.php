<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Texts\Embeddings;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * The embedding call returned no usable vector: an error answer of the proxy (an input over the model's token limit, a
 * rate limit, an outage), a response without the `embedding` array, or a vector of the wrong dimension. Thrown by
 * {@see \DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService::generateEmbeddingForText()} instead of
 * returning an empty vector, so no writer can persist a zero vector by accident. `$reason` names what the call
 * answered.
 */
class TextEmbeddingNotGeneratedException extends InternalErrorException
{
    /** @var string What the embedding call answered instead of a vector. */
    public string $reason;

    /** @var int Length in bytes of the text that was sent. */
    public int $inputBytes;

    /** @var bool The provider answered its context-length error (recognised by code or wording; for the log). */
    public bool $inputExceededModelLimit;

    /** @var bool The provider answered with an error (status 400+ or an error body) — no transport failure. */
    public bool $answeredWithError;

    public function __construct(string $reason, int $inputBytes, bool $inputExceededModelLimit = false, bool $answeredWithError = false)
    {
        $this->reason = $reason;
        $this->inputBytes = $inputBytes;
        $this->inputExceededModelLimit = $inputExceededModelLimit;
        $this->answeredWithError = $answeredWithError || $inputExceededModelLimit;
        parent::__construct("No embedding vector was generated for a text of $inputBytes bytes: $reason");
    }
}
