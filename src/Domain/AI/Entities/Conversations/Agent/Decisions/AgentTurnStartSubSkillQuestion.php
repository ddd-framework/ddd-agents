<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The sub-skill question of one skill that was loaded before the turn: the loaded skill's name and the question its
 * sub-skill pass asks. One child request of the turn-start request is sent per such question.
 *
 * @phpstan-consistent-constructor
 */
class AgentTurnStartSubSkillQuestion extends ValueObject
{
    /** @var string The name of the loaded skill whose sub-skills the question offers. */
    public string $loadedSkillName;

    /** @var AgentSkillPreloadDecisionQuestion The question of the loaded skill's sub-skill pass. */
    public AgentSkillPreloadDecisionQuestion $skillPreloadQuestion;

    public static function forLoadedSkill(string $loadedSkillName, AgentSkillPreloadDecisionQuestion $skillPreloadQuestion): static
    {
        $turnStartSubSkillQuestion = new static();
        $turnStartSubSkillQuestion->loadedSkillName = $loadedSkillName;
        $turnStartSubSkillQuestion->skillPreloadQuestion = $skillPreloadQuestion;
        return $turnStartSubSkillQuestion;
    }

    /** The key of a loaded skill's question: the loaded skill's name. */
    public static function uniqueKeyOfLoadedSkill(string $loadedSkillName): string
    {
        return self::class . '_' . $loadedSkillName;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyOfLoadedSkill($this->loadedSkillName);
    }
}
