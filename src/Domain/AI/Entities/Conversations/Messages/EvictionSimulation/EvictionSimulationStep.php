<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages\EvictionSimulation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One turn of a simulated conversation: the context size the turn reaches, the capabilities it loads, the tool it
 * uses and a note for the printout. Identified by its turn number ({@see EvictionSimulationSteps}).
 *
 * @phpstan-consistent-constructor
 */
class EvictionSimulationStep extends ValueObject
{
    /** @var int The turn number */
    public int $turnIndex = 0;

    /** @var int The context size in tokens at this turn */
    public int $contextInputTokens = 0;

    /** @var string[] The capabilities the turn loads (a tool of a skill loads here only when tools load with their skill) */
    public array $capabilityNamesToLoad = [];

    /** @var string|null The tool the turn uses (loaded on first use); null = the turn uses no tool */
    public ?string $usedToolName = null;

    /** @var string What happens in the turn, for the printout */
    public string $note = '';

    /**
     * @param int $turnIndex
     * @param int $contextInputTokens
     * @param string|null $usedToolName
     * @param string $note
     * @param string[] $capabilityNamesToLoad
     * @return static
     */
    public static function forTurn(int $turnIndex, int $contextInputTokens, ?string $usedToolName, string $note, array $capabilityNamesToLoad = []): static
    {
        $evictionSimulationStep = new static();
        $evictionSimulationStep->turnIndex = $turnIndex;
        $evictionSimulationStep->contextInputTokens = $contextInputTokens;
        $evictionSimulationStep->usedToolName = $usedToolName;
        $evictionSimulationStep->note = $note;
        $evictionSimulationStep->capabilityNamesToLoad = $capabilityNamesToLoad;
        return $evictionSimulationStep;
    }

    /**
     * @param int $turnIndex
     * @return string the key of the step of that turn in an {@see EvictionSimulationSteps}
     */
    public static function uniqueKeyForTurnIndex(int $turnIndex): string
    {
        return self::class . '_' . $turnIndex;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyForTurnIndex($this->turnIndex);
    }
}
