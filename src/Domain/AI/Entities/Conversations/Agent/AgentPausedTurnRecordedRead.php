<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One completed call of a read tool as the platform's record of a turn lists it
 * ({@see AgentPausedTurnRecord::$reads}): its result still holds, the model need not call it again with the same
 * arguments.
 *
 * @phpstan-consistent-constructor
 */
class AgentPausedTurnRecordedRead extends ValueObject
{
    /** @var string The called read tool */
    public string $toolName = '';

    /** @var int The index of the tool-call message in the conversation */
    public int $messageIndex = 0;

    /** @var string The call's arguments as one short sorted line, without the presentation arguments and the arguments the conversation's space fills; empty when none are left */
    public string $normalisedArguments = '';

    /**
     * @param string $toolName
     * @param int $messageIndex
     * @param string $normalisedArguments
     * @return static
     */
    public static function forReadOfMessage(string $toolName, int $messageIndex, string $normalisedArguments): static
    {
        $recordedRead = new static();
        $recordedRead->toolName = $toolName;
        $recordedRead->messageIndex = $messageIndex;
        $recordedRead->normalisedArguments = $normalisedArguments;
        return $recordedRead;
    }
}
