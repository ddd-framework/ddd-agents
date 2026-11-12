<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The exposed frontier of the compaction tree, per level (formerly `array<int, array<int, node>>`): built
 * once per round by {@see AIConversationCompactionNodes::buildExposedNodesByLevel()} and kept current across the
 * round's roll-ups ({@see replaceRolledUpGroupWithParent()}) instead of being recomputed — equal to a recompute over the
 * post-roll-up tree (RollUpFrontierIncrementalEqualsRecomputeTest).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCompactionExposedNodesByLevel extends ValueObject
{
    /** @var AIConversationCompactionNode[][] level → exposed nodes (coveredFrom ASC) */
    protected array $nodesByLevel = [];

    /** @param AIConversationCompactionNode[][] $nodesByLevel */
    public static function fromNodesByLevel(array $nodesByLevel): static
    {
        $exposedNodesByLevel = new static();
        $exposedNodesByLevel->nodesByLevel = $nodesByLevel;
        return $exposedNodesByLevel;
    }

    /** @return AIConversationCompactionNode[] the exposed nodes at $level (coveredFrom ASC); [] when none */
    public function getNodesAtLevel(int $level): array
    {
        return $this->nodesByLevel[$level] ?? [];
    }

    /** @return AIConversationCompactionNode[][] level → exposed nodes */
    public function toNodesByLevel(): array
    {
        return $this->nodesByLevel;
    }

    /**
     * The oldest contiguous group of $fanIn exposed nodes at $level aged past the $retentionPerLevel newest, or null —
     * the one roll-up selection the round's incremental loop and {@see AIConversationCompactionNodes::getNextRollUpGroup()}
     * share. Keeps $retentionPerLevel fine near the frontier; rolls only the oldest $fanIn, and only if they form a
     * hole-free contiguous run (else the parent would over-claim coverage).
     */
    public function buildNextRollUpGroupAtLevel(int $level, int $fanIn, int $retentionPerLevel): ?AIConversationCompactionRollUpGroup
    {
        if ($fanIn < 2) {
            return null;
        }
        $exposedAtLevel = $this->getNodesAtLevel($level);
        if (count($exposedAtLevel) - max(0, $retentionPerLevel) < $fanIn) {
            return null;
        }
        $childNodes = array_slice($exposedAtLevel, 0, $fanIn);
        for ($i = 1; $i < $fanIn; $i++) {
            if ($childNodes[$i]->coveredFromMessageIndex !== ($childNodes[$i - 1]->coveredToMessageIndex ?? PHP_INT_MAX) + 1) {
                return null;
            }
        }
        return AIConversationCompactionRollUpGroup::ofChildNodes($childNodes);
    }

    /**
     * The incremental frontier update after a roll-up: the group's children are now covered by $parentNode → they leave
     * their level (the oldest entries); the parent is the newest exposed node one level up → appended.
     */
    public function replaceRolledUpGroupWithParent(AIConversationCompactionRollUpGroup $rollUpGroup, AIConversationCompactionNode $parentNode): void
    {
        $childLevel = $rollUpGroup->getChildLevel();
        array_splice($this->nodesByLevel[$childLevel], 0, $rollUpGroup->countChildNodes());
        $this->nodesByLevel[$childLevel + 1][] = $parentNode;
    }
}
