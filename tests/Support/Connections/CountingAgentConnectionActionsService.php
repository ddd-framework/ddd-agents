<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Services\Connections\AgentConnectionActionsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionOperation;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** The audit with fixed counts: what the limits read, and the refusals it would record */
class CountingAgentConnectionActionsService extends AgentConnectionActionsService
{
    public int $doneInTheLastHour = 0;

    public int $doneInTheLastDay = 0;

    /** @var array<string, int> target (audit form) => sends to it in the last day */
    public array $doneByTarget = [];

    /** @var string[] the outcomes recorded */
    public array $recordedOutcomes = [];

    public function countOutwardDoneSince(int $agentConnectionId, DateTime $since): int
    {
        // the hour window starts at most two hours back, the day window further
        return $since > new DateTime('-2 hours') ? $this->doneInTheLastHour : $this->doneInTheLastDay;
    }

    public function countDoneToTargetSince(int $agentConnectionId, DateTime $since, string $target): int
    {
        return $this->doneByTarget[$target] ?? 0;
    }

    public function record(AgentConnectionOperation $agentConnectionOperation, string $outcome, string $target, ?string $contentHash, ?string $outcomeText, ?int $approvalRequestId): AgentConnectionAction
    {
        $this->recordedOutcomes[] = $outcome;
        return AgentConnectionAction::newInstance();
    }
}
