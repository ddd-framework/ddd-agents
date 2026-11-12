<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Services\Skills\AISkillsService;

/** The skills service with a fixed overlay and persona instead of the config roots. */
class AISkillsServiceWithFixedConfig extends AISkillsService
{
    /**
     * @param array<string, array<string, mixed>> $fixedSkillRequirements
     * @param array<string, mixed> $fixedAgentPersona
     */
    public function __construct(protected array $fixedSkillRequirements, protected array $fixedAgentPersona)
    {
    }

    public function getSkillRequirementsBySkillName(): array
    {
        return $this->fixedSkillRequirements;
    }

    public function getAgentPersona(): array
    {
        return $this->fixedAgentPersona;
    }
}
