<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Texts\Embeddings;

use DDD\Domain\Common\Entities\MathEntities\Vector;

/**
 * The embedding vector of an arbitrary text — the GENERIC semantic-search key for any RAG / similarity feature
 * (memory top-k retrieval, compaction-segment similarity, conversation search). A {@see Vector} value object,
 * usable directly as a `#[DatabaseColumn(vectorDimensions: …)]` column on any entity; its values are produced by
 * the host's subclass of {@see \DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService} (e.g. an Argus op
 * that POSTs to a LiteLLM proxy). Mirrors the `DDD\Domain\Common\Entities\Texts\Embeddings\TextEmbedding` of
 * another DDD application — kept generic on purpose so memory and compaction (and the next consumer) share ONE
 * embedding concept, not per-domain copies.
 *
 * Dimension: default `text-embedding-3-small`@1536 ({@see Vector::DIMENSION_OPENAI_EMBEDDING_SMALL}); the op can
 * switch to `3-large`@3072. The column dimension is fixed per consumer at migration time.
 *
 * @phpstan-consistent-constructor
 */
class TextEmbedding extends Vector
{
    /**
     * The FIRST attempt's input bound, in UTF-8 bytes. `text-embedding-3-small` and `-large` accept at most 8,192
     * tokens. Ordinary German or English prose runs at about four bytes per token, so 24,000 bytes are about 6,000
     * tokens and fit; text with many short tokens (URLs with random signatures, digits, emoji) may not. When the provider
     * answers with an error (whatever its wording), the call is retried once with {@see SAFE_MAX_INPUT_BYTES}.
     */
    public const int FIRST_ATTEMPT_MAX_INPUT_BYTES = 24000;

    /**
     * The provably safe input bound, in UTF-8 bytes — the retry after an error answer. The tokenizer is
     * byte-level: every token covers at least one byte, so 8,000 bytes can never exceed 8,000 tokens, whatever the
     * text holds.
     */
    public const int SAFE_MAX_INPUT_BYTES = 8000;

    /**
     * The text cut to `$maxInputBytes` on a character boundary (never inside a multi-byte character). A shorter text
     * comes back unchanged. Pure.
     */
    public static function buildInputWithinBytes(string $text, int $maxInputBytes = self::SAFE_MAX_INPUT_BYTES): string
    {
        if (strlen($text) <= $maxInputBytes) {
            return $text;
        }
        return mb_strcut($text, 0, max(1, $maxInputBytes), 'UTF-8');
    }

    /**
     * The all-zero vector of `$dimensions` values — the "not embedded" marker the vector columns default to, which every
     * search leaves out and the application's zero-embedding repair re-embeds. Only for a row that must be marked
     * un-embedded explicitly (an in-place edit whose old vector would otherwise stay); never a search key. Pure.
     */
    public static function zeroVectorOfDimension(int $dimensions): static
    {
        return new static(array_fill(0, $dimensions, 0.0));
    }

    /** Whether the vector holds exactly `$dimensions` values and at least one of them is not zero. Pure. */
    public function hasValuesOfDimension(int $dimensions): bool
    {
        return count($this->vectorValues ?? []) === $dimensions && !$this->isEmpty();
    }
}
