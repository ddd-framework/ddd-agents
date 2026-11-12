<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The sub-skill questions of the skills loaded before a turn, one per loaded skill, in the order the skills were
 * loaded.
 *
 * @property AgentTurnStartSubSkillQuestion[] $elements
 * @method AgentTurnStartSubSkillQuestion[] getElements()
 * @method AgentTurnStartSubSkillQuestion|null first()
 */
class AgentTurnStartSubSkillQuestions extends ObjectSet
{
    /** Adds the sub-skill question of a loaded skill; a skill the set already holds keeps its question. */
    public function addQuestionOfLoadedSkill(string $loadedSkillName, AgentSkillPreloadDecisionQuestion $skillPreloadQuestion): void
    {
        $turnStartSubSkillQuestion = AgentTurnStartSubSkillQuestion::forLoadedSkill($loadedSkillName, $skillPreloadQuestion);
        $this->add($turnStartSubSkillQuestion);
    }

    /** The sub-skill question of a loaded skill; null when the skill has none this turn. */
    public function getQuestionOfLoadedSkill(string $loadedSkillName): ?AgentSkillPreloadDecisionQuestion
    {
        $turnStartSubSkillQuestion = $this->getByUniqueKey(AgentTurnStartSubSkillQuestion::uniqueKeyOfLoadedSkill($loadedSkillName));
        return $turnStartSubSkillQuestion instanceof AgentTurnStartSubSkillQuestion ? $turnStartSubSkillQuestion->skillPreloadQuestion : null;
    }

    /** @return string[] The names of the loaded skills that have a question, in the set's order. */
    public function getLoadedSkillNames(): array
    {
        $loadedSkillNames = [];
        foreach ($this->getElements() as $turnStartSubSkillQuestion) {
            $loadedSkillNames[] = $turnStartSubSkillQuestion->loadedSkillName;
        }
        return $loadedSkillNames;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
