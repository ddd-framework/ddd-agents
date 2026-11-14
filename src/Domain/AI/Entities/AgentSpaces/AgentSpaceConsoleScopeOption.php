<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\AgentSpaces;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One console option by which an application names the space of a console command in its own words, declared by
 * {@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::getConsoleScopeOptions()}. The option takes a value;
 * the command reads it and asks the same service for the space
 * ({@see \DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService::findAgentSpaceForConsoleScopeOptionValues()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentSpaceConsoleScopeOption extends ValueObject
{
    /** @var string The option name without the leading dashes */
    public string $name = '';

    /** @var string The help text of the option */
    public string $description = '';

    /**
     * One option per name: the name is the key in {@see AgentSpaceConsoleScopeOptions}.
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        return self::class . '_' . $this->name;
    }

    /**
     * @param string $name the option name without the leading dashes
     * @param string $description the help text of the option
     * @return static
     */
    public static function forNameAndDescription(string $name, string $description): static
    {
        $agentSpaceConsoleScopeOption = new static();
        $agentSpaceConsoleScopeOption->name = $name;
        $agentSpaceConsoleScopeOption->description = $description;
        return $agentSpaceConsoleScopeOption;
    }
}
