<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The eviction figures of one loaded capability at one turn, before the eviction pass: what the agent's per-tool pass
 * reads to decide whether the capability leaves ({@see LoadedCapability::evictionScore()}). Identified by the
 * capability name ({@see EvictionSimulationScoreRows}).
 *
 * @phpstan-consistent-constructor
 */
class EvictionSimulationScoreRow extends ValueObject
{
    /** @var string The skill or tool name */
    public string $capabilityName = '';

    /** @var string {@see LoadedCapability::TYPE_SKILL} or {@see LoadedCapability::TYPE_TOOL} */
    public string $capabilityType = LoadedCapability::TYPE_TOOL;

    /** @var int Tokens the capability adds to every model call while it is loaded */
    public int $residentTokens = 0;

    /** @var bool Whether the capability is always provisioned ({@see LoadedCapability::$isAlwaysLoaded}) */
    public bool $isAlwaysLoaded = false;

    /** @var int Context tokens added since the capability was last used */
    public int $tokensSinceLastUse = 0;

    /** @var int Turns since the capability was last used */
    public int $turnsSinceLastUse = 0;

    /** @var float How cold the capability is ({@see LoadedCapability::staleness()}) */
    public float $staleness = 0.0;

    /** @var float How much its size weighs ({@see LoadedCapability::sizeFactor()}) */
    public float $sizeFactor = 0.0;

    /** @var float How much the carried load weighs ({@see LoadedCapability::pressureFactor()}) */
    public float $pressureFactor = 0.0;

    /** @var float The product of the three factors; a tool leaves the soft way at 1 or more */
    public float $evictionScore = 0.0;

    /**
     * The figures of a loaded capability at a turn.
     *
     * @param LoadedCapability $loadedCapability
     * @param int $contextInputTokens the context size at the turn
     * @param int $turnIndex the turn
     * @param int $carriedTokens the whole load carried before the pass
     * @param ContextRetentionSettings $settings the eviction thresholds
     * @return static
     */
    public static function forLoadedCapability(LoadedCapability $loadedCapability, int $contextInputTokens, int $turnIndex, int $carriedTokens, ContextRetentionSettings $settings): static
    {
        $evictionSimulationScoreRow = new static();
        $evictionSimulationScoreRow->capabilityName = $loadedCapability->name;
        $evictionSimulationScoreRow->capabilityType = $loadedCapability->type;
        $evictionSimulationScoreRow->residentTokens = $loadedCapability->residentTokens;
        $evictionSimulationScoreRow->isAlwaysLoaded = $loadedCapability->isAlwaysLoaded;
        $evictionSimulationScoreRow->tokensSinceLastUse = $loadedCapability->tokenDeltaSinceLastUse($contextInputTokens);
        $evictionSimulationScoreRow->turnsSinceLastUse = $loadedCapability->turnsSinceLastUse($turnIndex);
        $evictionSimulationScoreRow->staleness = $loadedCapability->staleness($contextInputTokens, $turnIndex, $settings);
        $evictionSimulationScoreRow->sizeFactor = $loadedCapability->sizeFactor($settings);
        $evictionSimulationScoreRow->pressureFactor = $loadedCapability->pressureFactor($carriedTokens, $settings);
        $evictionSimulationScoreRow->evictionScore = $loadedCapability->evictionScore($contextInputTokens, $turnIndex, $carriedTokens, $settings);
        return $evictionSimulationScoreRow;
    }

    /**
     * @param string $capabilityName
     * @return string the key of the row of that capability in an {@see EvictionSimulationScoreRows}
     */
    public static function uniqueKeyForCapabilityName(string $capabilityName): string
    {
        return self::class . '_' . $capabilityName;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForCapabilityName($this->capabilityName);
    }
}
