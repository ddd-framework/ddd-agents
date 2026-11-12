<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Eligibility;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a refused automation is logged with: the conversation, the schedule (and its account) or the tool the check ran
 * for. Every field is optional; a caller sets what it knows.
 *
 * @phpstan-consistent-constructor
 */
class AgentEligibilityLogContext extends ValueObject
{
    /** @var int|null The conversation the check ran for. */
    public ?int $conversationId = null;

    /** @var int|null The schedule the check ran for. */
    public ?int $scheduleId = null;

    /** @var int|null The account of that schedule. */
    public ?int $scheduleAccountId = null;

    /** @var string|null The tool the check ran for. */
    public ?string $toolName = null;

    /**
     * The context of a conversation.
     *
     * @param int|null $conversationId
     * @return self
     */
    public static function forConversation(?int $conversationId): self
    {
        $logContext = new static();
        $logContext->conversationId = $conversationId;
        return $logContext;
    }

    /**
     * The context of a schedule.
     *
     * @param int|null $scheduleId
     * @param int|null $scheduleAccountId
     * @return self
     */
    public static function forSchedule(?int $scheduleId, ?int $scheduleAccountId = null): self
    {
        $logContext = new static();
        $logContext->scheduleId = $scheduleId;
        $logContext->scheduleAccountId = $scheduleAccountId;
        return $logContext;
    }

    /**
     * The context of a tool call (inside a conversation, when known).
     *
     * @param string $toolName
     * @param int|null $conversationId
     * @return self
     */
    public static function forToolCall(string $toolName, ?int $conversationId = null): self
    {
        $logContext = new static();
        $logContext->toolName = $toolName;
        $logContext->conversationId = $conversationId;
        return $logContext;
    }

    /**
     * The set fields as the context of a log line, keyed by field name (the keys the platform logged before:
     * `conversationId`, `scheduleId`, `scheduleAccountId`, `toolName`). Pure.
     *
     * @return array<string, int|string>
     */
    public function buildLogFields(): array
    {
        return array_filter(
            [
                'toolName' => $this->toolName,
                'conversationId' => $this->conversationId,
                'scheduleId' => $this->scheduleId,
                'scheduleAccountId' => $this->scheduleAccountId,
            ],
            fn(int|string|null $fieldValue): bool => $fieldValue !== null
        );
    }
}
