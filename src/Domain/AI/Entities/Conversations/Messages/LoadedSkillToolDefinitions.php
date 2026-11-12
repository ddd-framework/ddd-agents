<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\RequestedAISkills;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The tool definitions a promotion may load, per skill: skill name → the
 * definitions of its packages, filtered by the agent space's feature flags (a tool gated tighter than its skill
 * never becomes a loaded capability), in the order of the skills given. Per skill, not flat: a tool promotion records
 * EVERY skill whose package carries the tool as a parent, so it must know which skill provides a definition. Built
 * once by the caller from resolved skills; the promotions read it and resolve nothing themselves.
 *
 * @phpstan-consistent-constructor
 */
class LoadedSkillToolDefinitions extends ValueObject
{
    /** @var ToolDefinitions[] skill name → its (flag-filtered) tool definitions, in the order given */
    protected array $toolDefinitionsBySkillName = [];

    /**
     * The definitions of the given skills' packages, each filtered by `$featureFlags` when given.
     *
     * @param iterable<AISkill> $skills
     */
    public static function fromSkills(iterable $skills, ?AgentFeatureFlagsInterface $featureFlags): static
    {
        $loadedSkillToolDefinitions = new static();
        foreach ($skills as $skill) {
            if (isset($loadedSkillToolDefinitions->toolDefinitionsBySkillName[$skill->name])) {
                continue;
            }
            $skillToolDefinitions = ToolDefinitions::forPackages($skill->getPackages());
            if ($featureFlags !== null) {
                $skillToolDefinitions = $skillToolDefinitions->filterByAvailableFeatureFlags($featureFlags);
            }
            $loadedSkillToolDefinitions->toolDefinitionsBySkillName[$skill->name] = $skillToolDefinitions;
        }
        return $loadedSkillToolDefinitions;
    }

    /** The definitions of the REQUESTED skills of a load (the ones a skill promotion reads; unknown names skipped). */
    public static function fromRequestedSkills(RequestedAISkills $requestedAISkills, ?AgentFeatureFlagsInterface $featureFlags): self
    {
        $requestedSkills = [];
        foreach ($requestedAISkills->requestedSkillNames as $requestedSkillName) {
            $requestedSkill = $requestedAISkills->getSkill($requestedSkillName);
            if ($requestedSkill !== null) {
                $requestedSkills[] = $requestedSkill;
            }
        }
        return self::fromSkills($requestedSkills, $featureFlags);
    }

    /** The definitions of one skill — empty for a skill not given. */
    public function getToolDefinitionsOf(string $skillName): ToolDefinitions
    {
        return $this->toolDefinitionsBySkillName[$skillName] ?? new ToolDefinitions();
    }

    /** @return string[] the skills given, in their order */
    public function getSkillNames(): array
    {
        return array_keys($this->toolDefinitionsBySkillName);
    }
}
