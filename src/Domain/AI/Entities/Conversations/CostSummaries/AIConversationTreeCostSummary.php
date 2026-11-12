<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\CostSummaries;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The rolled-up model spend + token totals of ONE conversation TREE (a root plus all its delegation
 * children), keyed by the root conversation id. Pure summary VO — the numbers come from the per-message
 * cost records; no repo, no logic beyond derivations over its own fields.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationTreeCostSummary extends ValueObject
{
    /** @var int The root conversation this tree total belongs to. */
    public int $rootConversationId = 0;

    /**
     * @var float Spend of the tree (root, delegation children, supervisor conversation), USD: the messages, their
     * tool calls and {@see $overheadCostsInUSD}.
     */
    public float $costInUSD = 0.0;

    /**
     * @var float The part of {@see $costInUSD} booked outside the messages and tool calls (decision calls, activity
     * lines, summary, compaction, memory, screening — {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$overheadCostsInUSD}).
     */
    public float $overheadCostsInUSD = 0.0;

    /** @var int Input tokens over the tree's messages (cached + uncached). */
    public int $inputTokens = 0;

    /** @var int Output tokens over the tree's messages. */
    public int $outputTokens = 0;

    /** @var int Message rows across the tree. */
    public int $messageCount = 0;

    /** @var int Conversations in the tree (root + children). */
    public int $conversationCount = 0;

    public function __construct(
        int $rootConversationId = 0,
        float $costInUSD = 0.0,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $messageCount = 0,
        int $conversationCount = 0
    ) {
        parent::__construct();
        $this->rootConversationId = $rootConversationId;
        $this->costInUSD = $costInUSD;
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->messageCount = $messageCount;
        $this->conversationCount = $conversationCount;
    }

    /**
     * The summary of one row of the per-tree totals query (`rootConversationId`, `costInUSD`, `inputTokens`,
     * `outputTokens`, `messageCount`, `conversationCount`); the spend rounded to 4 decimals.
     *
     * @param array<string, mixed> $treeTotalRow
     */
    public static function fromRow(array $treeTotalRow): static
    {
        $aIConversationTreeCostSummary = new static(
            (int)$treeTotalRow['rootConversationId'],
            round((float)$treeTotalRow['costInUSD'], 4),
            (int)$treeTotalRow['inputTokens'],
            (int)$treeTotalRow['outputTokens'],
            (int)$treeTotalRow['messageCount'],
            (int)$treeTotalRow['conversationCount']
        );
        $aIConversationTreeCostSummary->overheadCostsInUSD = round((float)($treeTotalRow['overheadCostsInUSD'] ?? 0.0), 4);
        return $aIConversationTreeCostSummary;
    }
}
