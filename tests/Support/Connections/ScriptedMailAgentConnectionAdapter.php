<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessage;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessages;
use DDD\Domain\AI\Services\Connections\Adapters\Fake\FakeAgentConnectionAdapter;

/** A mailbox that answers every search with the messages a test put in */
class ScriptedMailAgentConnectionAdapter extends FakeAgentConnectionAdapter
{
    public AgentConnectionMailMessages $inbox;

    public function __construct()
    {
        $this->inbox = new AgentConnectionMailMessages();
    }

    public function searchMail(string $query, int $maximumResults): AgentConnectionMailMessages
    {
        return $this->inbox;
    }

    public function readMailThread(string $threadId): AgentConnectionMailMessages
    {
        $threadMessages = new AgentConnectionMailMessages();
        foreach ($this->inbox->getElements() as $mailMessage) {
            if (($mailMessage->threadId ?? $mailMessage->messageId) === $threadId) {
                $threadMessages->add($mailMessage);
            }
        }
        return $threadMessages;
    }

    public function addMail(AgentConnectionMailMessage $mailMessage): void
    {
        $this->inbox->add($mailMessage);
    }
}
