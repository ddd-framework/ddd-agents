<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Mandates;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of a mandate grant ({@see \DDD\Domain\AI\Services\Mandates\AgentMandatesService::grantMandateFromDialogue()}):
 * the mandate that now covers the request — a new one, with the ACTIVE mandates it replaced, or the ACTIVE
 * one-instruction mandate of the same request that already covered it (nothing was created).
 *
 * @phpstan-consistent-constructor
 */
class AgentMandateGrantOutcome extends ValueObject
{
    public ?AgentMandate $agentMandate = null;

    /** @var AgentMandate[] the mandates the new grant superseded */
    public array $supersededMandates = [];

    /** Whether an ACTIVE one-instruction mandate of THIS request already covered it — nothing new was created. */
    public bool $requestAlreadyCovered = false;

    /**
     * @param AgentMandate[] $supersededMandates
     */
    public static function forGranted(AgentMandate $grantedAgentMandate, array $supersededMandates): static
    {
        $agentMandateGrantOutcome = new static();
        $agentMandateGrantOutcome->agentMandate = $grantedAgentMandate;
        $agentMandateGrantOutcome->supersededMandates = $supersededMandates;
        return $agentMandateGrantOutcome;
    }

    public static function forAlreadyCovered(AgentMandate $coveringAgentMandate): static
    {
        $agentMandateGrantOutcome = new static();
        $agentMandateGrantOutcome->agentMandate = $coveringAgentMandate;
        $agentMandateGrantOutcome->requestAlreadyCovered = true;
        return $agentMandateGrantOutcome;
    }
}
