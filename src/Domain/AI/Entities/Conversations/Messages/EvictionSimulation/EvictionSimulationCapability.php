<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One capability of the eviction simulator's catalog: a skill body or a tool schema, its resident size, whether it
 * is always provisioned and the skill a tool belongs to. Identified by its name ({@see EvictionSimulationCapabilities}).
 *
 * @phpstan-consistent-constructor
 */
class EvictionSimulationCapability extends ValueObject
{
    /** @var string The skill or tool name */
    public string $name = '';

    /** @var string {@see LoadedCapability::TYPE_SKILL} or {@see LoadedCapability::TYPE_TOOL} */
    public string $type = LoadedCapability::TYPE_TOOL;

    /** @var int Tokens the capability adds to every model call while it is loaded */
    public int $residentTokens = 0;

    /**
     * @var bool Whether the tool is always provisioned: a meta tool (no skill), or a tool of a skill that loads with
     * its skill even when tools load on first use ({@see LoadedCapability::$isAlwaysLoaded}); it pins nothing
     */
    public bool $isAlwaysLoaded = false;

    /** @var string|null The skill a tool belongs to; null for a skill or a meta tool */
    public ?string $parentSkillName = null;

    /**
     * @param string $name
     * @param string $type
     * @param int $residentTokens
     * @param bool $isAlwaysLoaded
     * @param string|null $parentSkillName
     * @return static
     */
    public static function forEntry(string $name, string $type, int $residentTokens, bool $isAlwaysLoaded, ?string $parentSkillName): static
    {
        $evictionSimulationCapability = new static();
        $evictionSimulationCapability->name = $name;
        $evictionSimulationCapability->type = $type;
        $evictionSimulationCapability->residentTokens = $residentTokens;
        $evictionSimulationCapability->isAlwaysLoaded = $isAlwaysLoaded;
        $evictionSimulationCapability->parentSkillName = $parentSkillName;
        return $evictionSimulationCapability;
    }

    /**
     * @param string $capabilityName
     * @return string the key of the capability of that name in an {@see EvictionSimulationCapabilities}
     */
    public static function uniqueKeyForName(string $capabilityName): string
    {
        return self::class . '_' . $capabilityName;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForName($this->name);
    }

    /** @return bool whether this is a tool of a skill (not a meta tool, not a skill) */
    public function isToolOfSkill(): bool
    {
        return $this->type === LoadedCapability::TYPE_TOOL && $this->parentSkillName !== null;
    }

    /**
     * The loaded capability the agent would carry after loading this one now.
     *
     * @param int $contextInputTokens the context size at the load
     * @param int $turnIndex the turn of the load
     * @return LoadedCapability
     */
    public function buildLoadedCapability(int $contextInputTokens, int $turnIndex): LoadedCapability
    {
        $loadedCapability = new LoadedCapability();
        $loadedCapability->name = $this->name;
        $loadedCapability->type = $this->type;
        $loadedCapability->residentTokens = $this->residentTokens;
        $loadedCapability->isAlwaysLoaded = $this->isAlwaysLoaded;
        $loadedCapability->parentSkillNames = $this->parentSkillName !== null ? [$this->parentSkillName] : [];
        $loadedCapability->markLoadedAt($contextInputTokens, $turnIndex);
        return $loadedCapability;
    }
}
