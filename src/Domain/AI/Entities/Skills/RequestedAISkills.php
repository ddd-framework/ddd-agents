<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Skills;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A `load_skills` request: the requested names in request order, and every name the load
 * path asks about — the requested ones and the call's already-loaded ones — RESOLVED once
 * ({@see \DDD\Domain\AI\Services\Skills\AISkillsService::resolveRequestedSkills()}); an unknown name resolves to null.
 * Reads nothing itself.
 */
class RequestedAISkills extends ValueObject
{
    /** @var string[] The requested names, request order, as the model sent them (strings). */
    public array $requestedSkillNames = [];

    /** @var string[] The skills the call's message has loaded already (the sub-skill gate's roots). */
    public array $loadedSkillNames = [];

    /** @var AISkills|null The skills the requested and loaded names resolved to; a name the set does not hold is unknown. */
    public ?AISkills $resolvedSkills = null;

    /** The resolved skill of `$skillName`, null when unknown. */
    public function getSkill(string $skillName): ?AISkill
    {
        return $this->resolvedSkills?->findByName($skillName);
    }

    /** Records the skill a requested or loaded name resolved to. */
    public function addResolvedSkill(AISkill $aiSkill): void
    {
        $this->resolvedSkills ??= new AISkills();
        $this->resolvedSkills->add($aiSkill);
    }

    /** @return AISkill[] The resolved skills, in the order they were recorded. */
    public function getResolvedSkills(): array
    {
        return $this->resolvedSkills?->getElements() ?? [];
    }

    /**
     * The same resolved request asking only `$skillNames` (a later admission pass) — each must be resolved here already.
     *
     * @param string[] $skillNames
     */
    public function withRequestedSkillNames(array $skillNames): self
    {
        $requestedAISkills = clone $this;
        $requestedAISkills->requestedSkillNames = array_values($skillNames);
        return $requestedAISkills;
    }

    /** Whether the request names at least one non-blank name. */
    public function namesAnySkill(): bool
    {
        foreach ($this->requestedSkillNames as $requestedSkillName) {
            if (trim($requestedSkillName) !== '') {
                return true;
            }
        }
        return false;
    }
}
