<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The context-rot wall of a model: 80 % of its hard context window. A token count the compaction reads
 * against the model — its effective context threshold, a purpose's context budget, the admin breakdown's model value —
 * never passes it. The one rule behind {@see CompactionModelLimits} — the model's own threshold, the compaction
 * threshold and the purpose budget clamp ({@see CompactionModelLimits::clampContextBudget()}).
 * A model without a positive window has no wall. Transient, never persisted.
 *
 * @phpstan-consistent-constructor
 */
class CompactionContextRotWall extends ValueObject
{
    /** The share of the hard window a model reads reliably. */
    public const float WINDOW_SHARE = 0.8;

    /** @var int|null The model's hard context window in tokens; null or non-positive = unknown. */
    public ?int $contextWindowTokens = null;

    public static function forContextWindowTokens(?int $contextWindowTokens): static
    {
        $compactionContextRotWall = new static();
        $compactionContextRotWall->contextWindowTokens = $contextWindowTokens;
        return $compactionContextRotWall;
    }

    public function hasWindow(): bool
    {
        return $this->contextWindowTokens !== null && $this->contextWindowTokens > 0;
    }

    /** `$tokens`, never above the wall: 80 % of the window, truncated (33 333 × 0.8 = 26 666.4 → 26 666). */
    public function capTokens(int $tokens): int
    {
        if (!$this->hasWindow()) {
            return $tokens;
        }
        return min($tokens, (int)($this->contextWindowTokens * self::WINDOW_SHARE));
    }
}
