<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Why the spawn does not start a sub-agent the model asked for: the failure reason the sub-agent's record stores
 * ({@see DelegationSubAgentRun::FAILURE_REASON_REFUSED}, {@see DelegationSubAgentRun::FAILURE_REASON_EARLIER_SUB_AGENT_FAILED})
 * and the corrective text the model reads as the answer of its `delegate` call. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService}; a {@see DelegationSubAgentRecord::notRun()}
 * record is written from it.
 *
 * @phpstan-consistent-constructor
 */
class DelegationSubAgentRefusal extends ValueObject
{
    /** @var string The failure reason of the sub-agent's not-run record */
    public string $failureReason = '';

    /** @var string What the model reads: why nothing was started and what to do instead */
    public string $corrective = '';

    /**
     * @param string $failureReason
     * @param string $corrective
     * @return static
     */
    public static function forFailureReasonAndCorrective(string $failureReason, string $corrective): static
    {
        $delegationSubAgentRefusal = new static();
        $delegationSubAgentRefusal->failureReason = $failureReason;
        $delegationSubAgentRefusal->corrective = $corrective;
        return $delegationSubAgentRefusal;
    }
}
