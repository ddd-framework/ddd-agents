<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a provider answered to a send, a create or a post.
 */
class AgentConnectionSendResult extends ValueObject
{
    public string $externalId = '';
    public ?string $threadId = null;
    public ?string $channelId = null;
}
