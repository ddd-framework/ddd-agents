<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * What the eviction simulator observed in a run ({@see EvictionSimulationRun::$turns}): one turn per turn number, in
 * the order played.
 *
 * @property EvictionSimulationTurn[] $elements
 * @method EvictionSimulationTurn[] getElements()
 * @method EvictionSimulationTurn|null first()
 * @method EvictionSimulationTurn|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class EvictionSimulationTurns extends ObjectSet
{
    /**
     * @param int $turnIndex
     * @return EvictionSimulationTurn|null the turn of that number
     */
    public function getByTurnIndex(int $turnIndex): ?EvictionSimulationTurn
    {
        return $this->getByUniqueKey(EvictionSimulationTurn::uniqueKeyForTurnIndex($turnIndex));
    }
}
