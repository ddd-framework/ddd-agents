<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One tool call of a turn as the platform's record of that turn lists it ({@see AgentPausedTurnRecord::$saved},
 * `$validatedNotSaved`, `$blocked`, `$refused`): the tool, the row the call was read from and the line the record
 * prints for it.
 *
 * @phpstan-consistent-constructor
 */
class AgentPausedTurnRecordedToolCall extends ValueObject
{
    /** @var string The called tool */
    public string $toolName = '';

    /** @var int The index of the tool-call message in the conversation */
    public int $messageIndex = 0;

    /** @var string The line the record prints: the preserved facts, the head of the result, or why the call was refused */
    public string $text = '';

    /** @var bool The tool answered `applied: false`: the write was refused by the tool itself; a later save of the same tool removes the call from the refused list */
    public bool $wasRefusedWithAppliedFalse = false;

    /**
     * @param string $toolName
     * @param int $messageIndex
     * @return static The recorded call, without its line
     */
    public static function forToolCallOfMessage(string $toolName, int $messageIndex): static
    {
        $recordedToolCall = new static();
        $recordedToolCall->toolName = $toolName;
        $recordedToolCall->messageIndex = $messageIndex;
        return $recordedToolCall;
    }
}
