<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One tool of a sub-skill that is offered but not loaded yet, while a root skill listing that sub-skill is loaded:
 * a `load_tool` call may name the tool, and loading it loads its sub-skill first.
 *
 * @phpstan-consistent-constructor
 */
class LoadableSubSkillTool extends ValueObject
{
    /** @var string The tool's name, as `load_tool` takes it */
    public string $toolName = '';

    /** @var string The not yet loaded sub-skill the tool belongs to */
    public string $subSkillName = '';

    /** @var string The tool's one-line summary, as the `load_tool` catalog shows it */
    public string $summary = '';

    /**
     * @param string $toolName
     * @param string $subSkillName
     * @param string $summary
     * @return static
     */
    public static function forToolOfSubSkill(string $toolName, string $subSkillName, string $summary): static
    {
        $loadableSubSkillTool = new static();
        $loadableSubSkillTool->toolName = $toolName;
        $loadableSubSkillTool->subSkillName = $subSkillName;
        $loadableSubSkillTool->summary = $summary;
        return $loadableSubSkillTool;
    }
}
