<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessage;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionMailMessages;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionTrigger;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Connections\Adapters\MailAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionTriggersRunService;

/** One trigger poll without a database: the registered mails, the runs it would start and the fired mark in memory */
class RecordingTriggersRunService extends AgentConnectionTriggersRunService
{
    public InMemoryAgentConnectionsService $inMemoryConnectionsService;

    public ScriptedMailAgentConnectionAdapter $mailAdapter;

    /** @var string[] message ids already registered (a repeated poll sees them again) */
    public array $registeredMessageIds = [];

    /** @var string[] the subjects of the started runs */
    public array $startedRunSubjects = [];

    public int $firedMarks = 0;

    public function __construct()
    {
        $this->inMemoryConnectionsService = new InMemoryAgentConnectionsService();
        $this->mailAdapter = new ScriptedMailAgentConnectionAdapter();
    }

    public function pollTriggerForTest(AgentConnection $agentConnection, AgentConnectionTrigger $agentConnectionTrigger): string
    {
        return $this->pollTrigger($agentConnection, $agentConnectionTrigger);
    }

    protected function findMailAdapterOfConnection(AgentConnection $agentConnection): ?MailAgentConnectionAdapter
    {
        return $this->mailAdapter;
    }

    protected function getAgentConnectionsService(): AgentConnectionsService
    {
        return $this->inMemoryConnectionsService;
    }

    protected function registerMail(AgentConnection $agentConnection, AgentConnectionMailMessage $mailMessage): bool
    {
        if (in_array($mailMessage->messageId, $this->registeredMessageIds, true)) {
            return false;
        }
        $this->registeredMessageIds[] = $mailMessage->messageId;
        return true;
    }

    protected function countRunsOfSenderInLastHour(AgentConnection $agentConnection, string $sender): int
    {
        return 0;
    }

    protected function startRun(AgentConnection $agentConnection, AgentConnectionTrigger $agentConnectionTrigger, AgentConnectionMailMessages $threadMessages): ?AIConversation
    {
        $this->startedRunSubjects[] = (string)$threadMessages->first()?->subject;
        return null;
    }

    protected function markFired(AgentConnectionTrigger $agentConnectionTrigger): void
    {
        $this->firedMarks++;
    }
}
