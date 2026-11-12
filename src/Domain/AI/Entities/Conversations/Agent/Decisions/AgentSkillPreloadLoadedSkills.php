<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The skills that are already loaded when the skill-preload decision of a turn is asked: context for the questions,
 * never an answer. Built once per turn
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentSkillPreloadDecisionsService}) and handed to every pass.
 */
class AgentSkillPreloadLoadedSkills extends ValueObject
{
    /** @var AgentSkillPreloadLoadedSkill[] In the order the skills were loaded; every skill once */
    public array $skills = [];

    /**
     * @param AgentSkillPreloadLoadedSkill $loadedSkill
     * @return void
     */
    public function addSkill(AgentSkillPreloadLoadedSkill $loadedSkill): void
    {
        $this->skills[] = $loadedSkill;
    }

    /** No skill is loaded. */
    public function isEmpty(): bool
    {
        return $this->skills === [];
    }

    /**
     * @return array<string, array{description: string, tools: array<string, string>}> The skills as the decision's
     *                state carries them: skill name → its description and its tools (name → summary)
     */
    public function toDecisionState(): array
    {
        $decisionState = [];
        foreach ($this->skills as $loadedSkill) {
            $decisionState[$loadedSkill->skillName] = ['description' => $loadedSkill->description, 'tools' => $loadedSkill->toolSummaries];
        }
        return $decisionState;
    }

    /**
     * @return array<string, array{toolNames: string[]}> The skills as the turn event's snapshot of the offer stores
     *                them: skill name → its tool names
     */
    public function toCatalogSnapshot(): array
    {
        $catalogSnapshot = [];
        foreach ($this->skills as $loadedSkill) {
            $catalogSnapshot[$loadedSkill->skillName] = ['toolNames' => $loadedSkill->toolNames];
        }
        return $catalogSnapshot;
    }
}
