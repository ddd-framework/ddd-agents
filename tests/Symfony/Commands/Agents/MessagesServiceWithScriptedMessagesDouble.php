<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/** The messages service answering the in-order read of a conversation with the rows the test scripted; reads nothing. */
class MessagesServiceWithScriptedMessagesDouble extends AIConversationMessagesService
{
    public ?AIConversationMessages $scriptedMessages = null;

    public function findAllMessagesForConversationInOrder(
        AIConversation $aiConversation,
        ?int $offset = null,
        ?int $limit = null,
        bool $useEntityRegistrCache = true
    ): ?AIConversationMessages {
        return $this->scriptedMessages;
    }
}
