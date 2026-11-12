<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the compaction summarizer returned for one leaf or roll-up input (formerly
 * `array{text, model, tokens}`): the gist and its provenance, stamped onto the node the round builds. Also the base of
 * the summarizer's Argus repo ({@see \DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIConversationCompaction}), which
 * writes {@see $summaryText} from the model output. Transient, never persisted.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCompactionSummary extends ValueObject
{
    /** @var string The gist of the summarized input, stored as given ({@see fromSummarizerOutput()} does not trim; the service's summarizeForCompaction() trims the model output before building it). */
    public string $summaryText = '';

    /** @var string|null The summarizer model that wrote the gist. */
    public ?string $aiModelName = null;

    /** @var int|null Output tokens of the summarizer call. */
    public ?int $outputTokens = null;

    public static function fromSummarizerOutput(string $summaryText, ?string $aiModelName, ?int $outputTokens): static
    {
        $compactionSummary = new static();
        $compactionSummary->summaryText = $summaryText;
        $compactionSummary->aiModelName = $aiModelName;
        $compactionSummary->outputTokens = $outputTokens;
        return $compactionSummary;
    }

    /** True when the summarizer produced no usable gist — the round stops its pass on it. */
    public function isEmpty(): bool
    {
        return trim($this->summaryText) === '';
    }
}
