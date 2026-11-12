<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Compaction;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The K exposed nodes of one level a roll-up consolidates into one node one level up — contiguous, oldest first
 * (formerly `AIConversationCompactionNode[]`). Picked by
 * {@see AIConversationCompactionExposedNodesByLevel::buildNextRollUpGroupAtLevel()}.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCompactionRollUpGroup extends ValueObject
{
    /** @var AIConversationCompactionNode[] */
    protected array $childNodes = [];

    /** @param AIConversationCompactionNode[] $childNodes */
    public static function ofChildNodes(array $childNodes): static
    {
        $rollUpGroup = new static();
        $rollUpGroup->childNodes = array_values($childNodes);
        return $rollUpGroup;
    }

    /** @return AIConversationCompactionNode[] the children, oldest first */
    public function getChildNodes(): array
    {
        return $this->childNodes;
    }

    /** The level the children sit on (their `level`; -1 when unset, the key the exposed-node map uses). */
    public function getChildLevel(): int
    {
        return isset($this->childNodes[0]) ? ($this->childNodes[0]->level ?? -1) : -1;
    }

    /**
     * The conversation the children belong to: the FIRST child's `aiConversationId` (null when it carries none — a
     * transient tree). Every child of one round's group carries the same value: a loaded node carries its
     * conversation's id, a node built in memory the id its conversation had then.
     */
    public function getAIConversationId(): ?int
    {
        return isset($this->childNodes[0]) ? ($this->childNodes[0]->aiConversationId ?? null) : null;
    }

    public function countChildNodes(): int
    {
        return count($this->childNodes);
    }

    /** The first message index the group covers: its FIRST child's start (the children are not checked). */
    public function getCoveredFromMessageIndex(): ?int
    {
        return ($this->childNodes[0] ?? null)?->coveredFromMessageIndex;
    }

    /** The last message index the group covers: its LAST child's end (the children are not checked). */
    public function getCoveredToMessageIndex(): ?int
    {
        return ($this->childNodes[count($this->childNodes) - 1] ?? null)?->coveredToMessageIndex;
    }

    /** The raw messages the children cover together; an unstamped child counts 0. */
    public function sumCoveredMessageCount(): int
    {
        $coveredMessageCount = 0;
        foreach ($this->childNodes as $childNode) {
            $coveredMessageCount += $childNode->coveredMessageCount ?? 0;
        }
        return $coveredMessageCount;
    }

    /** The raw-content tokens the children compacted away together; an unstamped child counts 0. */
    public function sumCoveredTokens(): int
    {
        $coveredTokens = 0;
        foreach ($this->childNodes as $childNode) {
            $coveredTokens += $childNode->coveredTokens ?? 0;
        }
        return $coveredTokens;
    }
}
