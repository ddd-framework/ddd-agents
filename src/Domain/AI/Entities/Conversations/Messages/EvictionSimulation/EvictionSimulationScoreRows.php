<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The eviction figures of every loaded capability at one turn ({@see EvictionSimulationTurn::$scoreRowsBeforeEviction}),
 * one row per capability name.
 *
 * @property EvictionSimulationScoreRow[] $elements
 * @method EvictionSimulationScoreRow[] getElements()
 * @method EvictionSimulationScoreRow|null first()
 * @method EvictionSimulationScoreRow|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class EvictionSimulationScoreRows extends ObjectSet
{
    /**
     * @param string $capabilityName
     * @return EvictionSimulationScoreRow|null the row of that capability
     */
    public function getByCapabilityName(string $capabilityName): ?EvictionSimulationScoreRow
    {
        return $this->getByUniqueKey(EvictionSimulationScoreRow::uniqueKeyForCapabilityName($capabilityName));
    }

    /**
     * Orders the rows by eviction score, highest first, compared at the two decimals the printout shows; rows of an
     * equal printed score keep their order.
     *
     * @return void
     */
    public function sortByEvictionScoreDescending(): void
    {
        $this->sort(
            static fn (EvictionSimulationScoreRow $firstRow, EvictionSimulationScoreRow $secondRow): int => round($secondRow->evictionScore, 2) <=> round($firstRow->evictionScore, 2)
        );
    }
}
