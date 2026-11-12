<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\CostSummaries;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One tool call in a conversation's trace — the ordered "what the model did" record: which tool, a short
 * argument + result summary, its cost, and when. The admin build-run trace surfaces these per work-unit so
 * latency (createdAt spacing), spend (costInUSD) and coverage (which tools ran / errored) are visible
 * without reading the raw conversation. Pure summary VO.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationToolCallTraceEntry extends ValueObject
{
    /** @var int The conversation this call belongs to. */
    public int $conversationId = 0;

    /** @var int Position in the conversation (append order). */
    public int $indexInConversation = 0;

    /** @var string|null The tool name (null for a non-tool message that slipped the filter). */
    public ?string $toolName = null;

    /** @var string|null Compact one-line summary of the call arguments. */
    public ?string $argumentsSummary = null;

    /** @var string|null Compact one-line summary of the result (or the error text). */
    public ?string $resultSummary = null;

    /** @var bool Whether the call ended in an error / failed status. */
    public bool $isError = false;

    /** @var float Model spend attributed to this message, USD. */
    public float $costInUSD = 0.0;

    /** @var string|null When the call was made. */
    public ?string $createdAt = null;

    /**
     * The trace entry of one tool-call message row of a tree (`aiConversationId`, `indexInConversation`,
     * `virtualToolName`, the stored `toolCall` JSON, `costInUSD`, `created`): the tool name from the virtual column,
     * else from the stored call; arguments and result cut to 240 characters; an error when the call FAILED.
     *
     * @param array<string, mixed> $traceRow
     */
    public static function fromRow(array $traceRow): static
    {
        $toolCallData = $traceRow['toolCall'] !== null ? json_decode((string)$traceRow['toolCall'], true) : null;
        $entry = new static();
        $entry->conversationId = (int)$traceRow['aiConversationId'];
        $entry->indexInConversation = (int)$traceRow['indexInConversation'];
        $entry->toolName = $traceRow['virtualToolName'] !== null
            ? (string)$traceRow['virtualToolName']
            : (is_array($toolCallData) && isset($toolCallData['name']) ? (string)$toolCallData['name'] : null);
        $entry->argumentsSummary = is_array($toolCallData) && isset($toolCallData['arguments'])
            ? mb_substr(json_encode($toolCallData['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 0, 240)
            : null;
        $entry->resultSummary = is_array($toolCallData) && ($toolCallData['result'] ?? null) !== null
            ? mb_substr((string)$toolCallData['result'], 0, 240)
            : null;
        $entry->isError = is_array($toolCallData) && ($toolCallData['status'] ?? null) === AIConversationMessage::STATUS_FAILED;
        $entry->costInUSD = round((float)$traceRow['costInUSD'], 4);
        $entry->createdAt = $traceRow['created'] !== null ? (string)$traceRow['created'] : null;
        return $entry;
    }
}
