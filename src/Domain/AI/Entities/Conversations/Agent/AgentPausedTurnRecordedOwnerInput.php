<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One message the owner wrote during a turn, as the platform's record of that turn quotes it
 * ({@see AgentPausedTurnRecord::$ownerInput}).
 *
 * @phpstan-consistent-constructor
 */
class AgentPausedTurnRecordedOwnerInput extends ValueObject
{
    /** @var int The index of the owner's message in the conversation */
    public int $messageIndex = 0;

    /** @var string The start of the owner's message, quoted as written */
    public string $text = '';

    /**
     * @param int $messageIndex
     * @param string $text
     * @return static
     */
    public static function forOwnerMessage(int $messageIndex, string $text): static
    {
        $recordedOwnerInput = new static();
        $recordedOwnerInput->messageIndex = $messageIndex;
        $recordedOwnerInput->text = $text;
        return $recordedOwnerInput;
    }
}
