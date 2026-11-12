<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A signal message that waits for the running turn to read it: the id of its row, its position in the conversation
 * and its text. Read without loading the message
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::findNewestSignalWaitingForRunningTurnOfSource()}),
 * so that a second signal of the same source can take the place of the waiting one.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationWaitingSignal extends ValueObject
{
    /** @var int The id of the signal's message row */
    public int $messageId = 0;

    /** @var int The signal's position in its conversation */
    public int $indexInConversation = 0;

    /** @var string The signal's text; empty when the row stores none */
    public string $message = '';

    /**
     * @param int $messageId
     * @param int $indexInConversation
     * @param string $message
     * @return static
     */
    public static function forMessageRow(int $messageId, int $indexInConversation, string $message): static
    {
        $waitingSignal = new static();
        $waitingSignal->messageId = $messageId;
        $waitingSignal->indexInConversation = $indexInConversation;
        $waitingSignal->message = $message;
        return $waitingSignal;
    }

    /**
     * @param string $text
     * @return bool Whether the waiting signal holds this text, compared without the white space around both
     */
    public function hasSameTextIgnoringSurroundingWhitespace(string $text): bool
    {
        return trim($this->message) === trim($text);
    }
}
