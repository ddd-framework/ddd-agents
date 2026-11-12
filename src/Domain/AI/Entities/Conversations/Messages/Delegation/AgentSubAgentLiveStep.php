<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One tool-call step of a running sub-agent, read live from the child's rows (never stored).
 *
 * @phpstan-consistent-constructor
 */
class AgentSubAgentLiveStep extends ValueObject
{
    /** The tool the step called. */
    public string $toolName = '';

    /** The call row's status (PENDING, COMPLETED, FAILED, …). */
    public string $status = '';

    /** When the call row was written. */
    public ?DateTime $createdAt = null;

    public static function forToolCall(string $toolName, string $status, ?DateTime $createdAt): static
    {
        $agentSubAgentLiveStep = new static();
        $agentSubAgentLiveStep->toolName = $toolName;
        $agentSubAgentLiveStep->status = $status;
        $agentSubAgentLiveStep->createdAt = $createdAt;
        return $agentSubAgentLiveStep;
    }

    /** `getWebPageAsMarkdown (COMPLETED, 20 s ago)` */
    public function buildStepLine(DateTime $now): string
    {
        $stepAge = $this->createdAt === null
            ? ''
            : ', ' . DelegationSubAgentRun::formatDuration(max(0, $now->getTimestamp() - $this->createdAt->getTimestamp())) . ' ago';
        return $this->toolName . ' (' . $this->status . $stepAge . ')';
    }
}
