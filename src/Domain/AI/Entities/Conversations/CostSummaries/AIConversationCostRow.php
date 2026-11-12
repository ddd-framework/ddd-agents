<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\CostSummaries;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One conversation's share of a tree's model spend — a breakdown row: the orchestrating root or one
 * delegation child, with its cost, token split (incl. cache-served input), message count, derived message
 * time span, and the model(s) that produced its messages. Pure summary VO.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationCostRow extends ValueObject
{
    /** @var int The conversation. */
    public int $conversationId = 0;

    /** @var bool Whether this is a delegation child (else the orchestrating root). */
    public bool $isDelegationChild = false;

    /** @var bool Whether this is the root's SUPERVISOR conversation ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$supervisedConversationId}). */
    public bool $isSupervisorConversation = false;

    /** @var string|null The conversation summary's title, when a summary exists. */
    public ?string $title = null;

    /** @var float Spend of this conversation, USD: its messages, their tool calls and {@see $overheadCostsInUSD}. */
    public float $costInUSD = 0.0;

    /**
     * @var float The part of {@see $costInUSD} booked outside the messages and tool calls (decision calls, activity
     * lines, summary, compaction, memory, screening — {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$overheadCostsInUSD}).
     */
    public float $overheadCostsInUSD = 0.0;

    /** @var int Input tokens over this conversation's messages (cached + uncached). */
    public int $inputTokens = 0;

    /** @var int Prompt-cache-served input tokens (a low share on a long conversation flags a broken cache path). */
    public int $cachedInputTokens = 0;

    /** @var int Output tokens over this conversation's messages. */
    public int $outputTokens = 0;

    /** @var int Message rows in this conversation. */
    public int $messageCount = 0;

    /** @var string|null First message timestamp (derived duration start). */
    public ?string $firstMessageAt = null;

    /** @var string|null Last message timestamp (derived duration end). */
    public ?string $lastMessageAt = null;

    /** @var string|null The AI model(s) that produced this conversation's messages (comma-joined on a model switch). */
    public ?string $modelNames = null;

    /**
     * The cost row of one row of the per-conversation totals query of a tree; the spend rounded to 4 decimals.
     *
     * @param array<string, mixed> $conversationCostRowValue
     */
    public static function fromRow(array $conversationCostRowValue): static
    {
        $aIConversationCostRow = new static();
        $aIConversationCostRow->conversationId = (int)$conversationCostRowValue['conversationId'];
        $aIConversationCostRow->isDelegationChild = (bool)$conversationCostRowValue['isDelegationChild'];
        $aIConversationCostRow->isSupervisorConversation = (bool)($conversationCostRowValue['isSupervisorConversation'] ?? false);
        $aIConversationCostRow->title = $conversationCostRowValue['title'] !== null ? (string)$conversationCostRowValue['title'] : null;
        $aIConversationCostRow->costInUSD = round((float)$conversationCostRowValue['costInUSD'], 4);
        $aIConversationCostRow->overheadCostsInUSD = round((float)($conversationCostRowValue['overheadCostsInUSD'] ?? 0.0), 4);
        $aIConversationCostRow->inputTokens = (int)$conversationCostRowValue['inputTokens'];
        $aIConversationCostRow->cachedInputTokens = (int)$conversationCostRowValue['cachedInputTokens'];
        $aIConversationCostRow->outputTokens = (int)$conversationCostRowValue['outputTokens'];
        $aIConversationCostRow->messageCount = (int)$conversationCostRowValue['messageCount'];
        $aIConversationCostRow->firstMessageAt = $conversationCostRowValue['firstMessageAt'] !== null ? (string)$conversationCostRowValue['firstMessageAt'] : null;
        $aIConversationCostRow->lastMessageAt = $conversationCostRowValue['lastMessageAt'] !== null ? (string)$conversationCostRowValue['lastMessageAt'] : null;
        $aIConversationCostRow->modelNames = $conversationCostRowValue['modelNames'] !== null ? (string)$conversationCostRowValue['modelNames'] : null;
        return $aIConversationCostRow;
    }
}
