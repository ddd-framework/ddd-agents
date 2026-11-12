<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One skill that is already loaded when the skill-preload decision of a turn is asked
 * ({@see AgentSkillPreloadLoadedSkills::$skills}): what the decision model reads about it (its description and the
 * summaries of its tools) and what the turn event's snapshot of the offer keeps of it (its tool names).
 *
 * @phpstan-consistent-constructor
 */
class AgentSkillPreloadLoadedSkill extends ValueObject
{
    /** @var string The loaded skill's name */
    public string $skillName = '';

    /** @var string The skill's entry in the decision catalog; the skill's name when the skill is unknown */
    public string $description = '';

    /** @var string[] The one-line summary of each tool the space may use, keyed by the tool's name */
    public array $toolSummaries = [];

    /** @var string[] The names of the tools the space may use */
    public array $toolNames = [];

    /**
     * @param string $skillName
     * @param string $description
     * @param string[] $toolSummaries keyed by the tool's name
     * @param string[] $toolNames
     * @return static
     */
    public static function forSkill(string $skillName, string $description, array $toolSummaries, array $toolNames): static
    {
        $loadedSkill = new static();
        $loadedSkill->skillName = $skillName;
        $loadedSkill->description = $description;
        $loadedSkill->toolSummaries = $toolSummaries;
        $loadedSkill->toolNames = $toolNames;
        return $loadedSkill;
    }
}
