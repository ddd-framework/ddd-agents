<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Capabilities of the eviction simulator, one per name, in the order they were added: the whole catalog
 * ({@see EvictionSimulationCatalog::$capabilities}) or a part of it, such as the tools of one skill.
 *
 * @property EvictionSimulationCapability[] $elements
 * @method EvictionSimulationCapability[] getElements()
 * @method EvictionSimulationCapability|null first()
 * @method EvictionSimulationCapability|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class EvictionSimulationCapabilities extends ObjectSet
{
    /**
     * Adds a capability; one of the same name already in the set is replaced in its place.
     *
     * @param EvictionSimulationCapability $evictionSimulationCapability
     * @return void
     */
    public function addCapability(EvictionSimulationCapability $evictionSimulationCapability): void
    {
        $this->replace($evictionSimulationCapability);
    }

    /**
     * @param string $capabilityName
     * @return EvictionSimulationCapability|null the capability of that name
     */
    public function getByName(string $capabilityName): ?EvictionSimulationCapability
    {
        return $this->getByUniqueKey(EvictionSimulationCapability::uniqueKeyForName($capabilityName));
    }

    /**
     * @param string $skillName
     * @return static the tools of the skill, in the order of this set
     */
    public function getToolsOfSkill(string $skillName): static
    {
        $toolsOfSkill = new static();
        foreach ($this->getElements() as $evictionSimulationCapability) {
            if ($evictionSimulationCapability->parentSkillName === $skillName) {
                $toolsOfSkill->add($evictionSimulationCapability);
            }
        }
        return $toolsOfSkill;
    }

    /** @return string[] the capability names, in the order of this set */
    public function getNames(): array
    {
        $capabilityNames = [];
        foreach ($this->getElements() as $evictionSimulationCapability) {
            $capabilityNames[] = $evictionSimulationCapability->name;
        }
        return $capabilityNames;
    }
}
