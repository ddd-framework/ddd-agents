<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;

/**
 * The folded turn-start request of one owner turn between its send and its application
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentTurnStartDecisionBatchService::sendTurnStartDecisions()} →
 * `applyTurnStartDecisions()`): the run and conversation it decides for, the sites' questions, the primary request and
 * the batch on the wire — or, with the breaker open, no batch at all. A plain runtime object, never persisted.
 */
class AgentTurnStartDecisionsInFlight
{
    public AgentRun $agentRun;

    public AIConversation $conversation;

    public ?string $runToken = null;

    public AgentDecisionModelSettings $decisionModelSettings;

    public AgentTurnStartSiteInputs $siteInputs;

    /** The primary request, filled from the sites' questions. */
    public ArgusAgentTurnStartDecision $primaryDecision;

    /** What the application reads; its call fields are filled when the batch is received. */
    public AgentTurnStartBatchResult $batchResult;

    /** The batch of the call; null when the breaker was open (nothing was sent). */
    public ?ArgusAgentDecisions $decisionBatch = null;

    /** The batch's requests on the wire; null when the breaker was open. */
    public ?AgentDecisionBatchInFlight $decisionBatchInFlight = null;

    /** True once the answers were received (applied, or only received after the stretch before the application failed). */
    public bool $received = false;
}
