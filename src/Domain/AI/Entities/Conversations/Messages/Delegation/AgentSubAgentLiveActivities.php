<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\Delegation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The live activity of the running sub-agents of one conversation, one per child.
 *
 * @property AgentSubAgentLiveActivity[] $elements
 * @method AgentSubAgentLiveActivity[] getElements()
 * @method AgentSubAgentLiveActivity|null first()
 */
class AgentSubAgentLiveActivities extends ObjectSet
{
    public function getForChildConversationId(?int $childConversationId): ?AgentSubAgentLiveActivity
    {
        if ($childConversationId === null) {
            return null;
        }
        foreach ($this->getElements() as $agentSubAgentLiveActivity) {
            if ($agentSubAgentLiveActivity->childConversationId === $childConversationId) {
                return $agentSubAgentLiveActivity;
            }
        }
        return null;
    }
}
