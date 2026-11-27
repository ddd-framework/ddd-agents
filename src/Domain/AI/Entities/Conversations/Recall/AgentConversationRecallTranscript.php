<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/** One page of the dialogue of an earlier conversation for the agent's readConversation */
class AgentConversationRecallTranscript extends ValueObject
{
    /** @var AgentConversationRecallRow The conversation read */
    public AgentConversationRecallRow $conversation;

    /** @var AgentConversationRecallMessage[] The messages of the page, oldest first */
    public array $messages = [];

    /** @var int|null The fromIndex of the next page; null when the page reached the end */
    public ?int $nextFromIndex = null;
}
