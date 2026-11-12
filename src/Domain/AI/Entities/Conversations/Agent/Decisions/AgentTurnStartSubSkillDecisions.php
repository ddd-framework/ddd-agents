<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The sub-skill requests of the skills loaded before a turn, one per loaded skill, in the order they were added to
 * the turn-start request.
 *
 * @property AgentTurnStartSubSkillDecision[] $elements
 * @method AgentTurnStartSubSkillDecision[] getElements()
 * @method AgentTurnStartSubSkillDecision|null first()
 */
class AgentTurnStartSubSkillDecisions extends ObjectSet
{
    /** Adds the sub-skill request of a loaded skill; a skill the set already holds keeps its request. */
    public function addDecisionOfLoadedSkill(string $loadedSkillName, ArgusAgentTurnStartDecision $turnStartDecision): void
    {
        $turnStartSubSkillDecision = AgentTurnStartSubSkillDecision::forLoadedSkill($loadedSkillName, $turnStartDecision);
        $this->add($turnStartSubSkillDecision);
    }

    /** The sub-skill request of a loaded skill; null when none was sent for it. */
    public function getDecisionOfLoadedSkill(string $loadedSkillName): ?ArgusAgentTurnStartDecision
    {
        $turnStartSubSkillDecision = $this->getByUniqueKey(AgentTurnStartSubSkillDecision::uniqueKeyOfLoadedSkill($loadedSkillName));
        return $turnStartSubSkillDecision instanceof AgentTurnStartSubSkillDecision ? $turnStartSubSkillDecision->turnStartDecision : null;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
