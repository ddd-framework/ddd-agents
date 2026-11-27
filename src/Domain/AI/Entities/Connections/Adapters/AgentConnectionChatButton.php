<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A button of a chat post; its value names an approval request by its token.
 */
class AgentConnectionChatButton extends ValueObject
{
    public string $label = '';
    /** @var string The action id: `approval:<token>:<optionKey>` */
    public string $actionValue = '';
    /** @var string|null primary or danger */
    public ?string $style = null;
}
