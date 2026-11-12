<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

/**
 * A publication of a written row (its embedding dispatch, its console line, its realtime tick) that may run only once
 * the row is visible to other processes — handed to
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::publishAfterCommit()}: run right away
 * outside a claim-fenced write, held by {@see AIConversationMessageWriteScope} and run after the outermost commit inside one.
 */
interface AIConversationMessageWritePublication
{
    /** Publish the written row. */
    public function publish(): void;
}
