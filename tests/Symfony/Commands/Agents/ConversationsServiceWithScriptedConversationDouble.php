<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;

/** The conversations service answering every lookup with the conversation the test scripted; reads nothing. */
class ConversationsServiceWithScriptedConversationDouble extends AIConversationsService
{
    public ?Entity $scriptedConversation = null;

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->scriptedConversation;
    }
}
