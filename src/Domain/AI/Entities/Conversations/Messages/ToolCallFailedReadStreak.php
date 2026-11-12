<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The failure streak of ONE identical read that already failed often enough in the conversation not to run again: how
 * often it failed and the text of its last failure. Built by
 * {@see AIConversationToolCallHistory::getRepeatedFailedReadResult()}; the dispatch quotes it in the refusal it answers
 * the call with.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallFailedReadStreak extends ValueObject
{
    /** @var int How many identical executed reads failed in a row */
    public int $failedCount = 0;

    /** @var string The result text of the last failure; '' when it carried none */
    public string $lastFailureResult = '';

    /**
     * @param int $failedCount
     * @param string $lastFailureResult
     * @return static
     */
    public static function forFailedCountAndLastFailureResult(int $failedCount, string $lastFailureResult): static
    {
        $toolCallFailedReadStreak = new static();
        $toolCallFailedReadStreak->failedCount = $failedCount;
        $toolCallFailedReadStreak->lastFailureResult = $lastFailureResult;
        return $toolCallFailedReadStreak;
    }
}
