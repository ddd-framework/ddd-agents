<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionStepArguments;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;

/**
 * One step through a connection as the gate sees it: who acts, in which conversation, which capability and
 * operation, and for an outward step its exact content (what a person approves), its recipients (the stage guard) and
 * the arguments it runs with (stored with an approval request; the approved step runs with exactly these).
 */
#[Exclude]
class AgentConnectionOperation
{
    public AgentConnection $agentConnection;
    public string $capability = '';
    public string $operation = '';
    public string $toolName = '';
    public ?int $actingAccountId = null;
    public ?AgentToolCallProvenance $callProvenance = null;
    /** @var int|null The space of the conversation the step runs in: only a release to it reaches the connection */
    public ?int $agentSpaceId = null;
    /** @var string[] lower-case addresses or user ids the step reaches */
    public array $recipients = [];
    public string $approvalSummary = '';
    public string $approvalContent = '';
    public AgentConnectionStepArguments $stepArguments;
    /** @var int|null Set when an approved request is executed: its hash must match */
    public ?int $approvedRequestId = null;

    public static function create(AgentConnection $agentConnection, string $capability, string $operation, string $toolName, ?int $actingAccountId, ?AgentToolCallProvenance $callProvenance): static
    {
        $agentConnectionOperation = new static();
        $agentConnectionOperation->agentConnection = $agentConnection;
        $agentConnectionOperation->capability = $capability;
        $agentConnectionOperation->operation = $operation;
        $agentConnectionOperation->toolName = $toolName;
        $agentConnectionOperation->actingAccountId = $actingAccountId;
        $agentConnectionOperation->callProvenance = $callProvenance;
        $agentConnectionOperation->stepArguments = AgentConnectionStepArguments::newInstance();
        $agentConnectionOperation->agentSpaceId = $callProvenance?->agentSpaceId;
        return $agentConnectionOperation;
    }

    public function getContentHash(): string
    {
        return hash('sha256', $this->capability . '|' . $this->operation . '|' . $this->stepArguments->toStoredJson());
    }

    public function getReach(): AgentConnectionReach
    {
        if ($this->callProvenance === null) {
            return AgentConnectionReach::inSpace($this->agentSpaceId);
        }
        return AgentConnectionReach::ofCall($this->callProvenance);
    }

    public function getConversationId(): ?int
    {
        return $this->callProvenance?->conversationId ?? $this->callProvenance?->rootConversationId;
    }
}
