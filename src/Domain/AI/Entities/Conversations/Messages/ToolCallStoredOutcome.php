<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The status and the result of a tool call as its message row holds them at the moment of the read
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::findPersistedToolCallOutcome()}). For a
 * reader whose loaded {@see ToolCall} may be older than the row.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallStoredOutcome extends ValueObject
{
    /** @var string|null The stored status; null when the row stores none */
    public ?string $status = null;

    /** @var string|null The stored result; null when the row stores none */
    public ?string $result = null;

    /**
     * @param string|null $status
     * @param string|null $result
     * @return static
     */
    public static function forStatusAndResult(?string $status, ?string $result): static
    {
        $toolCallStoredOutcome = new static();
        $toolCallStoredOutcome->status = $status;
        $toolCallStoredOutcome->result = $result;
        return $toolCallStoredOutcome;
    }

    /**
     * Puts what the row holds onto a loaded tool call; a value the row does not store leaves the loaded one.
     *
     * @param ToolCall $toolCall
     * @return void
     */
    public function applyToToolCall(ToolCall $toolCall): void
    {
        $toolCall->status = $this->status ?? $toolCall->status;
        $toolCall->result = $this->result ?? $toolCall->result;
    }
}
