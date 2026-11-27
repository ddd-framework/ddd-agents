<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Who the chat bot is in its workspace (Slack auth.test): the workspace (team), the bot user and its name.
 */
class AgentConnectionSlackBotIdentity extends ValueObject
{
    public string $teamId = '';
    public string $teamName = '';
    public string $botUserId = '';
    public string $botName = '';
}
