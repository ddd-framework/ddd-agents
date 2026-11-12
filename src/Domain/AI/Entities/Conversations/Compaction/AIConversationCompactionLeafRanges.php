<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The leaf ranges one compaction round closes, oldest first and contiguous (formerly a list of
 * `array{fromIndex, toIndex}`).
 *
 * @property AIConversationCompactionLeafRange[] $elements
 * @method AIConversationCompactionLeafRange[] getElements()
 * @method AIConversationCompactionLeafRange|null first()
 */
class AIConversationCompactionLeafRanges extends ObjectSet
{
    /** Append the range [$fromIndex .. $toIndex]. */
    public function addRange(int $fromIndex, int $toIndex): void
    {
        $leafRange = AIConversationCompactionLeafRange::between($fromIndex, $toIndex);
        $this->add($leafRange);
    }

    /** @return array<int, array{fromIndex: int, toIndex: int}> */
    public function toArrays(): array
    {
        return array_map(static fn (AIConversationCompactionLeafRange $leafRange): array => $leafRange->toArray(), $this->getElements());
    }
}
