<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\Base\Entities\ObjectSet;
use InvalidArgumentException;

/**
 * The scripted conversations the eviction simulator can play, one per name, in the order they are listed and played
 * ({@see EvictionSimulationScenario::buildBuiltInScenarios()}).
 *
 * @property EvictionSimulationScenario[] $elements
 * @method EvictionSimulationScenario[] getElements()
 * @method EvictionSimulationScenario|null first()
 * @method EvictionSimulationScenario|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class EvictionSimulationScenarios extends ObjectSet
{
    /**
     * Appends a scenario.
     *
     * @param EvictionSimulationScenario $evictionSimulationScenario
     * @return static
     * @throws InvalidArgumentException when the set already holds a scenario of the same name
     */
    public function addScenario(EvictionSimulationScenario $evictionSimulationScenario): static
    {
        if ($this->getByName($evictionSimulationScenario->name) !== null) {
            throw new InvalidArgumentException("A scenario named '{$evictionSimulationScenario->name}' is listed already.");
        }
        $this->add($evictionSimulationScenario);
        return $this;
    }

    /**
     * @param string $scenarioName
     * @return EvictionSimulationScenario|null the scenario of that name
     */
    public function getByName(string $scenarioName): ?EvictionSimulationScenario
    {
        return $this->getByUniqueKey(EvictionSimulationScenario::uniqueKeyForName($scenarioName));
    }

    /** @return string[] the scenario names, in the order of this set */
    public function getNames(): array
    {
        $scenarioNames = [];
        foreach ($this->getElements() as $evictionSimulationScenario) {
            $scenarioNames[] = $evictionSimulationScenario->name;
        }
        return $scenarioNames;
    }
}
