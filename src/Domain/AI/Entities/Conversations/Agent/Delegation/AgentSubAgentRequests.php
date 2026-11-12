<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The sub-agents one requester asks for at once, in request order: the tasks of a tool
 * result (`delegationTasks`, `backgroundOperations`) or the warm-ups of a purpose step. Each request carries ONE task and
 * becomes ONE synthetic `delegate` row.
 *
 * @property AgentSubAgentRequest[] $elements
 * @method AgentSubAgentRequest[] getElements()
 * @method AgentSubAgentRequest|null first()
 */
class AgentSubAgentRequests extends ObjectSet
{
    /** The longest wait any request declared before the parent's next model call (0 = none). */
    public function getLongestAwaitGraceSeconds(): int
    {
        $longestAwaitGraceSeconds = 0;
        foreach ($this->getElements() as $agentSubAgentRequest) {
            $longestAwaitGraceSeconds = max($longestAwaitGraceSeconds, $agentSubAgentRequest->awaitGraceSeconds);
        }
        return $longestAwaitGraceSeconds;
    }
}
