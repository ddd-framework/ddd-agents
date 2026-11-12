<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The catalog edges of ONE skill: the tool names its packages list and its `subSkillNames`. {@see LoadedSkillGroups}
 * joins them with the loaded capabilities; {@see LoadedSkillGroups::buildFor()} builds one per resolved loaded skill,
 * a container-less test builds them from names.
 *
 * @phpstan-consistent-constructor
 */
class LoadedSkillCatalogEdges extends ValueObject
{
    /** @var string[] The tool names the skill's catalog entry lists */
    public array $toolNames = [];

    /** @var string[] The sub-skill names the skill's catalog entry lists */
    public array $subSkillNames = [];

    /**
     * @param string[] $toolNames
     * @param string[] $subSkillNames
     * @return static
     */
    public static function forToolAndSubSkillNames(array $toolNames, array $subSkillNames): static
    {
        $loadedSkillCatalogEdges = new static();
        $loadedSkillCatalogEdges->toolNames = $toolNames;
        $loadedSkillCatalogEdges->subSkillNames = $subSkillNames;
        return $loadedSkillCatalogEdges;
    }

    /**
     * @param AISkill $aISkill A resolved skill
     * @return static
     */
    public static function forSkill(AISkill $aISkill): static
    {
        return static::forToolAndSubSkillNames($aISkill->getToolNames(), $aISkill->subSkillNames);
    }
}
