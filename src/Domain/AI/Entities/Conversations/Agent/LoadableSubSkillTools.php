<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The tools of the offered, not yet loaded sub-skills of the loaded root skills
 * ({@see OfferedSkillCatalog::buildLoadableSubSkillTools()}), in the order of the offered catalog. A tool name appears
 * once: a tool two sub-skills carry belongs to the first of them.
 */
class LoadableSubSkillTools extends ValueObject
{
    /** @var LoadableSubSkillTool[] In the order of the offered catalog; every tool name once */
    public array $tools = [];

    /**
     * Adds the tool `$toolName` of the sub-skill `$subSkillName`, unless a tool of that name is already listed.
     *
     * @param string $toolName
     * @param string $subSkillName
     * @param string $summary the tool's one-line summary
     * @return void
     */
    public function addToolOfSubSkillOnce(string $toolName, string $subSkillName, string $summary): void
    {
        if ($this->getToolNamed($toolName) !== null) {
            return;
        }
        $this->tools[] = LoadableSubSkillTool::forToolOfSubSkill($toolName, $subSkillName, $summary);
    }

    /**
     * @param string $toolName
     * @return LoadableSubSkillTool|null The listed tool of that name; null when none is listed
     */
    public function getToolNamed(string $toolName): ?LoadableSubSkillTool
    {
        foreach ($this->tools as $loadableSubSkillTool) {
            if ($loadableSubSkillTool->toolName === $toolName) {
                return $loadableSubSkillTool;
            }
        }
        return null;
    }

    /** @return string[] The names of the listed tools, in their order */
    public function getToolNames(): array
    {
        return array_map(static fn (LoadableSubSkillTool $loadableSubSkillTool): string => $loadableSubSkillTool->toolName, $this->tools);
    }

    /** No sub-skill tool is listed. */
    public function isEmpty(): bool
    {
        return $this->tools === [];
    }
}
