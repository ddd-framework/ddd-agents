<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;

/** The send and the receive of a decision batch, scripted. */
class AgentDecisionCallsServiceTestSplitBatch extends ArgusAgentDecisions
{
    public ?\Throwable $sendError = null;

    public ?\Throwable $receiveError = null;

    public bool $primaryFailsAtTheTransportLevel = false;

    public bool $sent = false;

    public bool $received = false;

    public function sendDecisionRequests(float $timeoutSeconds): void
    {
        if ($this->sendError !== null) {
            throw $this->sendError;
        }
        $this->sent = true;
    }

    public function receiveDecisionResponses(): void
    {
        $this->received = true;
        if ($this->receiveError !== null) {
            throw $this->receiveError;
        }
        if ($this->primaryFailsAtTheTransportLevel) {
            foreach ($this->getElements() as $child) {
                $child->transportFailed = true;
                $child->transportFailureReason = 'timeout';
            }
        }
    }
}
