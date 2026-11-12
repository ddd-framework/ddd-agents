<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentStallRecoveryDecision;

/** The Argus side of a split decision batch, scripted: what the batch itself decides — timeouts, deadline — runs for real. */
class AgentDecisionCallsServiceTestTransportScriptedBatch extends ArgusAgentDecisions
{
    /** @var ArgusAgentStallRecoveryDecision[] the children that get no answer (rejected) on receive */
    public array $childrenRejectedOnReceive = [];

    public ?int $httpStatusCodeOfRejectedChildren = null;

    /** @var int|float|null the hrtime the scripted requests are written at; now when null */
    public int|float|null $requestsWrittenAtHr = null;

    /** @var float[] every bounded write drive's limit */
    public array $writeDriveSeconds = [];

    public int|float|null $receiveDeadlineHr = null;

    /** False = the request bytes are not written by the send's bounded drive (nor before the receive). */
    public bool $requestsWrittenBySend = true;

    /** True = the send cannot split: it loads the batch at once and rejects the children there. */
    public bool $sendFallsBackToTheBlockingLoad = false;

    /** The timeout the blocking fallback load ran under. */
    public ?float $blockingLoadTimeoutSeconds = null;

    /** @var float|null the transport timeout instead of the decision timeout plus the slack */
    public ?float $transportTimeoutSecondsInsteadOfTheSlack = null;

    protected function buildTransportTimeoutSeconds(float $decisionTimeoutSeconds): float
    {
        return $this->transportTimeoutSecondsInsteadOfTheSlack ?? parent::buildTransportTimeoutSeconds($decisionTimeoutSeconds);
    }

    protected function sendDecisionRequestsOverTheArgusLoad(float $blockingLoadTimeoutSeconds): bool
    {
        if (!$this->sendFallsBackToTheBlockingLoad) {
            return true;
        }
        $this->blockingLoadTimeoutSeconds = $blockingLoadTimeoutSeconds;
        $this->rejectChildren();
        return false;
    }

    protected function advanceDecisionRequestsUntilWritten(float $maxSeconds): bool
    {
        $this->writeDriveSeconds[] = $maxSeconds;
        if (!$this->requestsWrittenBySend) {
            return false;
        }
        $this->requestsWrittenAtHr ??= hrtime(true);
        return true;
    }

    protected function findDecisionRequestsWrittenAtHr(): int|float|null
    {
        return $this->requestsWrittenAtHr;
    }

    protected function receiveDecisionResponsesOverTheArgusLoad(int|float|null $deadlineHr): void
    {
        $this->receiveDeadlineHr = $deadlineHr;
        $this->rejectChildren();
    }

    protected function rejectChildren(): void
    {
        foreach ($this->childrenRejectedOnReceive as $rejectedChild) {
            $rejectedChild->transportFailed = true;
            $rejectedChild->transportFailureReason = 'GuzzleHttp\\Promise\\CancellationException: Promise has been cancelled';
            $rejectedChild->httpStatusCode = $this->httpStatusCodeOfRejectedChildren;
        }
    }
}
