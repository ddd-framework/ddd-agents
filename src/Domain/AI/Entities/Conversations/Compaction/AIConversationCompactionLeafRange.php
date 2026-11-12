<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One level-0 leaf to close: the contiguous message-index range a compaction leaf summarizes (formerly
 * `array{fromIndex, toIndex}`). Built by the conversation's leaf planners.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCompactionLeafRange extends ValueObject
{
    /** The first covered `indexInConversation`. */
    public int $fromIndex = 0;

    /** The last covered `indexInConversation` (inclusive). */
    public int $toIndex = 0;

    public static function between(int $fromIndex, int $toIndex): static
    {
        $leafRange = new static();
        $leafRange->fromIndex = $fromIndex;
        $leafRange->toIndex = $toIndex;
        return $leafRange;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->fromIndex . '-' . $this->toIndex);
    }

    /** @return array{fromIndex: int, toIndex: int} */
    public function toArray(): array
    {
        return ['fromIndex' => $this->fromIndex, 'toIndex' => $this->toIndex];
    }
}
