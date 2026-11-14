<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\Base\Entities\ObjectSet;
use InvalidArgumentException;

/**
 * The turns of a scripted conversation ({@see EvictionSimulationScenario::$steps}), one step per turn number, in the
 * order they are played.
 *
 * @property EvictionSimulationStep[] $elements
 * @method EvictionSimulationStep[] getElements()
 * @method EvictionSimulationStep|null first()
 * @method EvictionSimulationStep|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class EvictionSimulationSteps extends ObjectSet
{
    /**
     * Appends a step.
     *
     * @param EvictionSimulationStep $evictionSimulationStep
     * @return static
     * @throws InvalidArgumentException when the set already holds a step of the same turn number
     */
    public function addStep(EvictionSimulationStep $evictionSimulationStep): static
    {
        if ($this->getByTurnIndex($evictionSimulationStep->turnIndex) !== null) {
            throw new InvalidArgumentException("Turn {$evictionSimulationStep->turnIndex} has a step already; every turn has one step.");
        }
        $this->add($evictionSimulationStep);
        return $this;
    }

    /**
     * @param int $turnIndex
     * @return EvictionSimulationStep|null the step of that turn
     */
    public function getByTurnIndex(int $turnIndex): ?EvictionSimulationStep
    {
        return $this->getByUniqueKey(EvictionSimulationStep::uniqueKeyForTurnIndex($turnIndex));
    }
}
