<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One chat message (Slack). Text written by others: untrusted content.
 */
class AgentConnectionChatMessage extends ValueObject
{
    public string $channelId = '';
    public ?string $threadId = null;
    public string $messageId = '';
    public ?string $authorExternalUserId = null;
    public ?string $authorName = null;
    public string $text = '';
    public ?DateTime $postedAt = null;
    public bool $isFromAgent = false;
}
