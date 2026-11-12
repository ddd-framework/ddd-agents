<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallDecodedResult;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One tool call of the conversation as the `$from` resolution sees it: its id, its status, and what
 * its stored result offers a binding — the structured shape and the listed entity rows
 * ({@see ComposedViewBindableResultRecord}). Built from the call row in memory or from the stored `toolCall` column
 * (the call row outside the loaded window). Reads nothing.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewToolCallResult extends ValueObject
{
    /** @var string The provider's tool call id. */
    public string $toolCallId = '';

    /** @var string|null The call's status (an {@see AIConversationMessage}::STATUS_* value). */
    public ?string $status = null;

    /** @var bool The call's result is an error (the executor's `isError`), or the result was removed for security. */
    public bool $resultIsUnusable = false;

    /** @var mixed The structured shape a path addresses; null when the result offers none. */
    public mixed $bindableData = null;

    /** @var bool The result offers a structured shape at all ({@see $bindableData} may still be an empty list). */
    public bool $hasBindableData = false;

    /** @var int|null The byte size of a structured shape too large to bind; null otherwise. */
    public ?int $bindableDataOmittedBytes = null;

    /** @var ComposedViewBindableResultRecord|null The result's bindable record; null when it stored none. */
    public ?ComposedViewBindableResultRecord $bindableResultRecord = null;

    public static function fromToolCall(ToolCall $toolCall): self
    {
        return self::fromStoredValues((string)($toolCall->toolCallId ?? ''), $toolCall->status ?? null, $toolCall->result ?? null, $toolCall->bindableResult, $toolCall->isRemovedForSecurity());
    }

    /**
     * The call from its stored values: id, status, raw result text and the `bindableResult` key of the `toolCall`
     * column ({@see ToolCall::$bindableResult}).
     *
     * @param array<string, mixed>|null $storedBindableResult
     */
    public static function fromStoredValues(string $toolCallId, ?string $status, ?string $resultText, ?array $storedBindableResult = null, bool $resultWasRemovedForSecurity = false): static
    {
        $composedViewToolCallResult = new static();
        $composedViewToolCallResult->toolCallId = $toolCallId;
        $composedViewToolCallResult->status = $status;
        $toolCallDecodedResult = ToolCallDecodedResult::fromResultText($resultText);
        $composedViewToolCallResult->resultIsUnusable = $resultWasRemovedForSecurity || $toolCallDecodedResult->isError
            || trim((string)$resultText) === ToolCall::RESULT_REMOVED_FOR_SECURITY;
        if ($composedViewToolCallResult->resultIsUnusable) {
            return $composedViewToolCallResult;
        }
        $bindableResultRecord = ComposedViewBindableResultRecord::fromStoredArray($storedBindableResult);
        $composedViewToolCallResult->bindableResultRecord = $bindableResultRecord;
        if ($bindableResultRecord !== null && !$bindableResultRecord->dataIsStructuredContent) {
            $composedViewToolCallResult->bindableDataOmittedBytes = $bindableResultRecord->dataOmittedBytes;
            $composedViewToolCallResult->hasBindableData = $bindableResultRecord->dataOmittedBytes === null;
            $composedViewToolCallResult->bindableData = $bindableResultRecord->data;
            return $composedViewToolCallResult;
        }
        // a structured result's `structuredContent` IS the result DTO; a markdown result's holds only the loop-control fields
        if ($toolCallDecodedResult->structuredContent !== null && ($bindableResultRecord !== null || $toolCallDecodedResult->isStructuredShapeResult())) {
            $structuredContentBytes = strlen((string)json_encode($toolCallDecodedResult->structuredContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($structuredContentBytes > ComposedViewBindableResultRecord::MAX_DATA_BYTES) {
                $composedViewToolCallResult->bindableDataOmittedBytes = $structuredContentBytes;
                return $composedViewToolCallResult;
            }
            $composedViewToolCallResult->hasBindableData = true;
            $composedViewToolCallResult->bindableData = $toolCallDecodedResult->structuredContent;
        }
        return $composedViewToolCallResult;
    }

    /** The call ran to its end: COMPLETED, FAILED or CANCELLED — a binding to it resolves now or fails. */
    public function hasEnded(): bool
    {
        return in_array($this->status, [AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_FAILED, AIConversationMessage::STATUS_CANCELLED], true);
    }

    /** @return \DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceRow[] */
    public function getEntityReferenceRows(): array
    {
        return $this->bindableResultRecord !== null ? $this->bindableResultRecord->entityReferenceRows : [];
    }

    /** The key of a tool call's result: the same for every class derived from this one, so a set finds it by the id. */
    public static function uniqueKeyOfToolCall(string $toolCallId): string
    {
        return self::class . '_' . $toolCallId;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyOfToolCall($this->toolCallId);
    }
}
