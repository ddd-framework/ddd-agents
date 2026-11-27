<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A chat post the agent sends: exactly what a person approves.
 */
class AgentConnectionChatPost extends ValueObject
{
    public string $channelId = '';
    public ?string $threadId = null;
    public string $text = '';
    /** @var AgentConnectionChatButtons|null buttons under the post (approvals) */
    public ?AgentConnectionChatButtons $buttons = null;

    public function addButton(AgentConnectionChatButton $chatButton): void
    {
        $this->buttons ??= new AgentConnectionChatButtons();
        $this->buttons->add($chatButton);
    }
    /** @var string|null An existing post that is replaced (a closed escalation shortened to its outcome) */
    public ?string $replacesMessageId = null;

    public function describeForApproval(): string
    {
        return 'Channel: ' . $this->channelId . ($this->threadId !== null ? ' (thread ' . $this->threadId . ')' : '') . "\n\n" . $this->text;
    }
}
