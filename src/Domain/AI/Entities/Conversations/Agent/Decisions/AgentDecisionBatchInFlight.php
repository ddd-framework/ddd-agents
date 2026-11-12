<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use Throwable;

/**
 * A decision batch whose requests were sent and whose answers are not received yet
 * ({@see \DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService::sendDecisionBatchWithoutBreakerCheck()}): the
 * batch, the site it answers, when the call started and what the send threw — a refusal of our own gate or a transport
 * error is classified when the batch is received, exactly as a one-shot call classifies it. A plain runtime object,
 * never persisted.
 */
class AgentDecisionBatchInFlight
{
    public ArgusAgentDecisions $decisionBatch;

    /** @var object|null The child whose answer the site reads; null when the batch as a whole is the site's answer. */
    public ?object $primaryDecision = null;

    public int $breakerSeconds = 0;

    /** The site named in a log line of our own error and in a trip reason without a primary. */
    public string $siteLabel = '';

    /** @var int|float The hrtime the call started at. */
    public int|float $callStartedAtHr = 0;

    /** What the send threw (it sent nothing then); null when the requests travel or the batch was loaded at once. */
    public ?Throwable $sendError = null;
}
