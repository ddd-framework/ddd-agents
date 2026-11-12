<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Conversations;

use RuntimeException;
use Throwable;

/**
 * The conversation's message-index lock ({@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::lockMessageIndex()})
 * could not be acquired within its wait, twice in a row — the guarded write was NOT run: an unguarded allocation
 * would let two writers merge their rows in the index upsert. The agent loop ends its turn LOUDLY on it (a logged
 * failure + the FAILED terminal, so the lost-turn recovery re-drives), never the silent claim-lost exit; the API
 * writers let it surface as an error. A plain RuntimeException: not a DDD HTTP exception, not a lost claim.
 */
class MessageIndexLockUnavailableException extends RuntimeException
{
    public function __construct(public readonly int $conversationId, ?Throwable $previous = null)
    {
        parent::__construct(
            'Message index lock unavailable for conversation ' . $conversationId . ' — the row was not written.',
            0,
            $previous
        );
    }
}
