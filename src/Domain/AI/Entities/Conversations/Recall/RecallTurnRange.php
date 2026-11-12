<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE inclusive `indexInConversation` window of conversation recall, resolved from a back-reference phrasing
 * ("at the beginning", "recently") by {@see resolveFromPhrasing()}; the search service applies it as an extra
 * `indexInConversation BETWEEN :min AND :max` filter — because "at the beginning" is a FILTER, not a similarity
 * boost. No range (null) = no temporal anchor → search the whole conversation. No DB/model.
 *
 * @phpstan-consistent-constructor
 */
class RecallTurnRange extends ValueObject
{
    /** Fraction of the conversation treated as "the beginning" / "the end" edge. */
    public const float EDGE_FRACTION = 0.25;

    /** Phrases anchoring to the START of the conversation. */
    protected const array BEGINNING_PHRASES = ['beginning', 'start', 'first', 'initially', 'originally', 'at the top'];

    /** Phrases anchoring to the END (most recent) of the conversation. */
    protected const array RECENT_PHRASES = ['recent', 'lately', 'just now', 'latest', 'a moment ago'];

    /** @var int The lowest `indexInConversation` of the window (inclusive) */
    public int $minIndex = 0;

    /** @var int The highest `indexInConversation` of the window (inclusive) */
    public int $maxIndex = 0;

    /**
     * @param int $minIndex
     * @param int $maxIndex
     * @return static
     */
    public static function forMinAndMaxIndex(int $minIndex, int $maxIndex): static
    {
        $recallTurnRange = new static();
        $recallTurnRange->minIndex = $minIndex;
        $recallTurnRange->maxIndex = $maxIndex;
        return $recallTurnRange;
    }

    /**
     * Resolve a phrasing into an inclusive `[minIndex, maxIndex]` over `indexInConversation`, or null when the phrasing
     * carries no temporal anchor (search the whole conversation). The edge width is `EDGE_FRACTION` of the conversation
     * length (at least 1). Recent wins over beginning when a phrasing mentions both (the latest reference dominates).
     *
     * @param string $phrasing
     * @param int $highestIndexInConversation
     * @return static|null
     */
    public static function resolveFromPhrasing(string $phrasing, int $highestIndexInConversation): ?static
    {
        if ($highestIndexInConversation < 0) {
            return null;
        }
        $edgeWidth = (int)max(1, (int)ceil($highestIndexInConversation * self::EDGE_FRACTION));
        $lowered = mb_strtolower($phrasing);

        if (self::containsAny($lowered, self::RECENT_PHRASES)) {
            return static::forMinAndMaxIndex(max(0, $highestIndexInConversation - $edgeWidth), $highestIndexInConversation);
        }
        if (self::containsAny($lowered, self::BEGINNING_PHRASES)) {
            return static::forMinAndMaxIndex(0, min($edgeWidth, $highestIndexInConversation));
        }
        return null;
    }

    /** @param list<string> $needles */
    protected static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
