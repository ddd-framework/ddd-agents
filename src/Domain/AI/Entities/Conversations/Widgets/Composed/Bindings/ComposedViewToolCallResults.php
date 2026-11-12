<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The tool calls of one conversation a `ComposedView` block names, by tool call id: each id
 * names exactly one call row — an id two rows carry is AMBIGUOUS and resolves nothing (never picked). An id the set
 * does not know names no call of this conversation (yet). Built by
 * {@see \DDD\Domain\AI\Services\Conversations\ComposedViewBindingResolutionService} from the loaded rows and, for ids
 * not loaded, the stored rows. Reads nothing.
 *
 * @property ComposedViewToolCallResult[] $elements
 * @method ComposedViewToolCallResult[] getElements()
 * @method ComposedViewToolCallResult|null first()
 */
class ComposedViewToolCallResults extends ObjectSet
{
    /** @var string[] The ids more than one call row carries. */
    public array $ambiguousToolCallIds = [];

    /** Adds a call row; a second row with the same id makes the id ambiguous. */
    public function addToolCallResult(ComposedViewToolCallResult $composedViewToolCallResult): void
    {
        $toolCallId = $composedViewToolCallResult->toolCallId;
        if ($toolCallId === '') {
            return;
        }
        if ($this->knowsToolCallId($toolCallId)) {
            if (!$this->isAmbiguous($toolCallId)) {
                $this->ambiguousToolCallIds[] = $toolCallId;
            }
            return;
        }
        $this->add($composedViewToolCallResult);
    }

    public function findToolCallResult(string $toolCallId): ?ComposedViewToolCallResult
    {
        if ($this->isAmbiguous($toolCallId)) {
            return null;
        }
        $composedViewToolCallResult = $this->getByUniqueKey(ComposedViewToolCallResult::uniqueKeyOfToolCall($toolCallId));
        return $composedViewToolCallResult instanceof ComposedViewToolCallResult ? $composedViewToolCallResult : null;
    }

    public function isAmbiguous(string $toolCallId): bool
    {
        return in_array($toolCallId, $this->ambiguousToolCallIds, true);
    }

    /** Whether the set knows the id at all (one row or several). */
    public function knowsToolCallId(string $toolCallId): bool
    {
        return $this->getByUniqueKey(ComposedViewToolCallResult::uniqueKeyOfToolCall($toolCallId)) !== null;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
