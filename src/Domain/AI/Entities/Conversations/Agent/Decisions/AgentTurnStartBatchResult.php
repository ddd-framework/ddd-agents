<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentOwnerMessageInjectionDecision;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * How the folded turn-start request went: the shared classification every site reads
 * ({@see $decidedBy}: null = a usable answer set, else a FALLBACK_*), the answered requests, and the batch's identity
 * and latency. A REFUSED request (our own gate) stamps every site `fallback:refused`.
 */
class AgentTurnStartBatchResult extends ValueObject
{
    public function __construct()
    {
        parent::__construct();
        $this->subSkillDecisions = new AgentTurnStartSubSkillDecisions();
    }

    /** Our own gate refused the request (budget, scope, envelope) — {@see $decidedBy} is `fallback:refused`. */
    public bool $refused = false;

    /** Null = the request answered; else the batch's FALLBACK_* (breaker, refused, transport, our own error). */
    public ?string $decidedBy = null;

    public ?string $transportFailureReason = null;

    /** The first 400 characters of the primary request's raw response, for the trace. */
    public ?string $rawResponseExcerpt = null;

    public ?int $elapsedMs = null;

    /** The primary request — null when the breaker was open (no call was made). */
    public ?ArgusAgentTurnStartDecision $primaryDecision = null;

    /** @var AgentTurnStartSubSkillDecisions The sub-skill requests, one per skill loaded before the turn. */
    public AgentTurnStartSubSkillDecisions $subSkillDecisions;

    public ?ArgusAgentOwnerMessageInjectionDecision $ownerMessageInjectionDecision = null;

    public ?string $batchId = null;

    /**
     * The fallback a sub-skill child is applied with: an answered child stands on its own; an unanswered one takes the
     * batch's fallback or its own transport failure (null = it answered).
     */
    public function getSubSkillDecidedBy(string $loadedSkillName): ?string
    {
        $subSkillDecision = $this->subSkillDecisions->getDecisionOfLoadedSkill($loadedSkillName);
        if ($subSkillDecision?->decided === true) {
            return null;
        }
        return $this->decidedBy ?? ($subSkillDecision?->transportFailed === true ? AgentSkillPreloadDecision::FALLBACK_TRANSPORT : null);
    }

    /**
     * Whether a child whose answer may close the circuit breaker brought a PARSABLE answer (the one close rule): the primary request when it carries a site and at least one of its sites got a usable
     * answer ({@see ArgusAgentTurnStartDecision::hasAUsableSiteAnswer()} — not the AND of all its sites), or a sub-skill
     * child with its answer read. The owner-message injection check never closes the breaker.
     */
    public function hasAParsableBreakerRelevantAnswer(AgentTurnStartSiteInputs $siteInputs): bool
    {
        if ($siteInputs->primaryRequestCarriesASite()
            && ($this->primaryDecision?->decided === true || $this->primaryDecision?->hasAUsableSiteAnswer() === true)
        ) {
            return true;
        }
        foreach ($this->subSkillDecisions->getElements() as $turnStartSubSkillDecision) {
            if ($turnStartSubSkillDecision->turnStartDecision->decided) {
                return true;
            }
        }
        return false;
    }

    /** The failure reason a sub-skill child is recorded with — its own, else the batch's; null when it answered. */
    public function getSubSkillFailureReason(string $loadedSkillName): ?string
    {
        if ($this->getSubSkillDecidedBy($loadedSkillName) === null) {
            return null;
        }
        return $this->subSkillDecisions->getDecisionOfLoadedSkill($loadedSkillName)?->transportFailureReason ?? $this->transportFailureReason;
    }

    /**
     * The payload of the primary request's own cost/latency event.
     *
     * @return array<string, mixed>
     */
    public function buildRequestEventPayload(AgentTurnStartSiteInputs $siteInputs): array
    {
        return [
            'sites' => $siteInputs->getRequestedSiteNames(),
            'batchId' => $this->batchId,
            'decidedBy' => $this->decidedBy ?? 'answered',
            'transportFailureReason' => $this->transportFailureReason,
            'modelVersion' => $this->primaryDecision?->modelVersion,
        ];
    }
}
