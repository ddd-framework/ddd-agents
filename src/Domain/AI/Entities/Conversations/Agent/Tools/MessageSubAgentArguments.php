<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The arguments of one `message_sub_agent` call: the number of the sub-agent and the message to it, the
 * message cleaned like an owner's text and cut to {@see MESSAGE_MAX_LENGTH}. Read once from the call.
 *
 * @phpstan-consistent-constructor
 */
class MessageSubAgentArguments extends ValueObject
{
    /** The longest message one call carries. */
    public const int MESSAGE_MAX_LENGTH = 4000;

    /** The record number (`#4` → 4); null when the call names none. */
    public ?int $subAgentNumber = null;

    /** The message, trimmed; empty when the call carries none. */
    public string $message = '';

    /** @param array<string, mixed> $arguments */
    public static function fromToolCallArguments(array $arguments): static
    {
        $messageSubAgentArguments = new static();
        $messageSubAgentArguments->subAgentNumber = CheckDelegationsTool::readSubAgentNumberFromArguments($arguments);
        $messageArgument = $arguments['message'] ?? null;
        $messageSubAgentArguments->message = is_string($messageArgument)
            ? trim(mb_substr(AIConversationMessage::sanitizeUserText($messageArgument), 0, self::MESSAGE_MAX_LENGTH))
            : '';
        return $messageSubAgentArguments;
    }

    /** What the call misses (a number, a message), as the model reads it; null when both are there. */
    public function buildArgumentsCorrective(): ?string
    {
        if ($this->subAgentNumber === null) {
            return 'Not sent: name the sub-agent by its number in subAgent (the #N its delegate call answered with).';
        }
        if ($this->message === '') {
            return 'Not sent: message is empty — write what sub-agent #' . $this->subAgentNumber . ' should do or know.';
        }
        return null;
    }

    /** The same sub-agent and the same message (whitespace at the ends ignored). */
    public function isSameMessageAs(self $otherArguments): bool
    {
        return $this->subAgentNumber === $otherArguments->subAgentNumber && $this->message === $otherArguments->message;
    }
}
