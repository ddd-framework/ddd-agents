<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE loaded skill's group ({@see LoadedSkillGroups}): the loaded tools that belong to it and the loaded sub-skills
 * under it, each in the order the catalog edges and then the recorded parent links named them.
 *
 * @phpstan-consistent-constructor
 */
class LoadedSkillGroup extends ValueObject
{
    /** The skill whose group this is. */
    public string $skillName = '';

    /** @var string[] The loaded tools of the group. */
    public array $toolNames = [];

    /** @var string[] The loaded sub-skills under the skill. */
    public array $subSkillNames = [];

    /**
     * @param string[] $toolNames
     * @param string[] $subSkillNames
     */
    public static function create(string $skillName, array $toolNames, array $subSkillNames): static
    {
        $loadedSkillGroup = new static();
        $loadedSkillGroup->skillName = $skillName;
        $loadedSkillGroup->toolNames = $toolNames;
        $loadedSkillGroup->subSkillNames = $subSkillNames;
        return $loadedSkillGroup;
    }

    public function hasToolName(string $toolName): bool
    {
        return in_array($toolName, $this->toolNames, true);
    }

    public function hasSubSkillName(string $subSkillName): bool
    {
        return in_array($subSkillName, $this->subSkillNames, true);
    }
}
