<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** One message of an earlier conversation as the agent's readConversation shows it */
class AgentConversationRecallMessage extends ValueObject
{
    /** @var int The message's position in its conversation */
    public int $indexInConversation = 0;

    /** @var string USER or ASSISTANT */
    public string $role = '';

    /** @var DateTime|null When the message was written */
    public ?DateTime $created = null;

    /** @var string The message's text (widget blocks removed, long text cut) */
    public string $text = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->indexInConversation);
    }
}
