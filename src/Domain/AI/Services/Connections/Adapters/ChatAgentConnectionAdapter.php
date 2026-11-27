<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatMessages;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatPost;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;

/** Capability CHAT (Slack). */
interface ChatAgentConnectionAdapter
{
    public function readChatThread(string $channelId, string $threadId, int $maximumMessages): AgentConnectionChatMessages;

    /** Posts, or replaces the post `replacesMessageId` names */
    public function postChatMessage(AgentConnectionChatPost $chatPost): AgentConnectionSendResult;

    /** The verified e-mail of an external user (the account link) */
    public function findVerifiedEmailOfUser(string $externalUserId): ?string;
}
