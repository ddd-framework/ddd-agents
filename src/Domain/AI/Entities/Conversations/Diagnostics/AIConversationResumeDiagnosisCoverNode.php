<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One summary node of the cover a resume diagnosis loaded ({@see AIConversationResumeDiagnosis::$coverNodes}): the
 * message range it replaces and its size before and after summarizing. A value the node does not carry is null.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationResumeDiagnosisCoverNode extends ValueObject
{
    /** @var int|null The node's level in the summary tree */
    public ?int $level = null;

    /** @var int|null The index of the first message the node replaces */
    public ?int $coveredFromMessageIndex = null;

    /** @var int|null The index of the last message the node replaces */
    public ?int $coveredToMessageIndex = null;

    /** @var int|null The number of messages the node replaces */
    public ?int $coveredMessageCount = null;

    /** @var int|null The tokens of the messages the node replaces */
    public ?int $coveredTokens = null;

    /** @var int|null The tokens of the node's summary */
    public ?int $summaryTokens = null;

    /**
     * @param AIConversationCompactionNode $compactionNode
     * @return static
     */
    public static function forCompactionNode(AIConversationCompactionNode $compactionNode): static
    {
        $coverNode = new static();
        $coverNode->level = $compactionNode->level ?? null;
        $coverNode->coveredFromMessageIndex = $compactionNode->coveredFromMessageIndex ?? null;
        $coverNode->coveredToMessageIndex = $compactionNode->coveredToMessageIndex ?? null;
        $coverNode->coveredMessageCount = $compactionNode->coveredMessageCount ?? null;
        $coverNode->coveredTokens = $compactionNode->coveredTokens ?? null;
        $coverNode->summaryTokens = $compactionNode->summaryTokens ?? null;
        return $coverNode;
    }

    /**
     * @return string[] The six values as they are printed, in the order level, first index, last index, message
     *                  count, covered tokens, summary tokens; `—` for a value the node does not carry
     */
    public function getPrintedValues(): array
    {
        return [
            (string)($this->level ?? '—'),
            (string)($this->coveredFromMessageIndex ?? '—'),
            (string)($this->coveredToMessageIndex ?? '—'),
            (string)($this->coveredMessageCount ?? '—'),
            (string)($this->coveredTokens ?? '—'),
            (string)($this->summaryTokens ?? '—'),
        ];
    }
}
